<?php
class ZkBase {
    const TRANS_SEC_MSEC = 1000 * 1000; 
    protected $strPath = "/lock";
    protected $ftSleepCycle = 0.1;
    protected static $objZk = null;
    protected static $intNodeFlags = Zookeeper::EPHEMERAL | Zookeeper::SEQUENCE;
    protected static $arrDefaultAcl = array(
		array(
			"perms"=>Zookeeper::PERM_ALL,
			"scheme"=>"world",
			"id"=>"anyone",
		),
	); 

    public function __construct($arrHosts, $ftTimeout){
        $strHosts = trim(implode($arrHosts, ','));
        if(empty($strHosts)){
            throw new RuntimeException("empty zookeeper host");
        }
        if(static::$objZk == null){
            $this->__initZk($strHosts, $ftTimeout);
        }
    }

    private function __initZk($strHosts, $ftTimeout = 3){
        static::$objZk = new zookeeper(); 
        $bolHadConn = false;
        static::$objZk->connect("172.17.0.10:2181", function()use(&$bolHadConn){$bolHadConn = true;});
        $ftDeadline = microtime(true) + $ftTimeout;
        while(microtime(true) <= $ftDeadline){
            if($bolHadConn && static::$objZk->getState() == Zookeeper::CONNECTED_STATE){
                break;
            }
            usleep($this->ftSleepCycle * static::TRANS_SEC_MSEC);  
        }
    }


    public function setLockPath($strPath){
        $this->strPath = $strPath;        
    }

    public function setSleepCycle($ftSleepCycle){
        $this->ftSleepCycle = $ftSleepCycle;        
    }

    protected function _createNode($strKey, $intFlags=null){
        $strLockKey = static::$objZk->create(
            $strKey, //path
            1,//value
            static::$arrDefaultAcl, //acl
            $intFlags //flag
        );

        if(!$strLockKey){
            throw new RuntimeException("failed create node".$strKey);
        }
        return $strLockKey;
    }

    public function unlock($strKey){
        static::$objZk->delete($strKey);
    }

    protected function _checkPath($strPath){
        return static::$objZk->exists($strPath); 
    }

    protected function _autoMakePath($strPath){
        if($strPath == '/'){
            return true;
        }
        $this->_autoMakePath($this->_getParentPath($strPath));
        if(!$this->_checkPath($strPath)){
            $this->_createNode($strPath);
        }
        return;
    }

    protected function _getIndex($strKey){
        $arrInfo = explode('-', $strKey);
        return end($arrInfo);
    }

    protected function _getParentPath($strPath){
        return dirname($strPath);
    }

    protected function _getSelfName($strPath){
        return basename($strPath);
    }
}

class ZkXlock extends ZkBase{

    public function lock($strKey, $ftTimeout=3){
        if(empty($strKey)){
            throw new RuntimeException("empty key name");
        }
        $strFullKey = $this->strPath . '/' . $strKey . '-'; 
        $strParentPath = $this->_getParentPath($strFullKey);
        $this->_autoMakePath($strParentPath);
        $strLockKey = $this->_createNode($strFullKey, static::$intNodeFlags);

        $bolLock = false;
        $bolNodeChange = true;
        $ftDeadline = microtime(true) + $ftTimeout;
        $strIndex = $this->_getIndex($strLockKey);
        while(microtime(true) <= $ftDeadline){
            if($bolNodeChange){
                $strMaxMinIndex = -1; //当前小于自己的最大index
                $arrSiblingNode = static::$objZk->getChildren($strParentPath); 
                foreach($arrSiblingNode as $strNode){
                    $strNodeIndex = $this->_getIndex($strNode);
                    if(intval($strNodeIndex) < intval($strIndex)){
                        $strMaxMinIndex = max($strMaxMinIndex, $strNodeIndex);
                    }
                }
                if($strMaxMinIndex == -1){
                    $bolLock = true;    
                    break;
                }
                $bolNodeChange = false;
                //watch MaxMinIndex 
                $bolExists = static::$objZk->exists($strFullKey . $strMaxMinIndex, function() use(&$bolNodeChange){$bolNodeChange = true;});
                if(!$bolExists){
                    $bolNodeChange = true;
                }
            }
            usleep($this->ftSleepCycle * static::TRANS_SEC_MSEC);  
        }
        if(!$bolLock){
            $this->unlock($strLockKey);
            return false;
        }
        return $strLockKey;
    }

}

class ZkSlock extends ZkBase{
    const READ_LOCK = 1;
    const WRITE_LOCK = 2;  
    private $strRlockPre = "read";
    private $strWlockPre = "write";

    public function wlock($strKey, $ftTimeout=3){
        return $this->__lock($strKey, self::WRITE_LOCK, $ftTimeout);
    }

    public function rlock($strKey, $ftTimeout=3){
        return $this->__lock($strKey, self::READ_LOCK, $ftTimeout);
    }

    private function __lock($strKey, $intLockMode, $ftTimeout){
        $strFullKey = $this->__makeFullKey($strKey, $intLockMode); 
        $strParentPath = $this->_getParentPath($strFullKey);
        $this->_autoMakePath($strParentPath);
        $strLockKey = $this->_createNode($strFullKey, static::$intNodeFlags);
        if(!$this->__waitForLock($strLockKey, $intLockMode, $ftTimeout)){
            $this->unlock($strLockKey);
            return false;
        }
        return $strLockKey;
    }

    //rlock只要等待wlock释放， wlock需要等待所有的锁释放
    private function __waitForLock($strLockKey, $intLockMode, $ftTimeout){
        $bolLock = false;
        $bolNodeChange = true;
        $ftDeadline = microtime(true) + $ftTimeout;
        $strIndex = $this->_getIndex($strLockKey);
        $strParentPath = $this->_getParentPath($strLockKey);
        while(microtime(true) <= $ftDeadline){
            if($bolNodeChange){
                $strMaxMinIndex = -1;
                $strWatchNode = null;
                $arrSiblingNode = static::$objZk->getChildren($strParentPath); 
                foreach($arrSiblingNode as $strNode){
                    $strNodeIndex = $this->_getIndex($strNode);
                    if(intval($strNodeIndex) >= intval($strIndex)){
                        continue;
                    }
                    if($intLockMode == self::READ_LOCK && $this->__getLockMode($strNode) == self::READ_LOCK){
                        continue; 
                    }    
                    if(intval($strMaxMinIndex) < intval($strNodeIndex)){
                        $strMaxMinIndex = $strNodeIndex;   
                        $strWatchNode = $strNode;
                    }
                } 
                //no min lock, get lock 
                if($strMaxMinIndex == -1){
                    $bolLock = true;    
                    break;
                }
                $bolNodeChange = false;
                //watch MaxMinIndex 
                $strWatchNode = $strParentPath . '/' . $strWatchNode;
                $bolExists = static::$objZk->exists($strWatchNode, function() use(&$bolNodeChange){$bolNodeChange = true;});
                if(!$bolExists){
                    $bolNodeChange = true;
                }
            }

            usleep($this->ftSleepCycle * static::TRANS_SEC_MSEC);  
        }
        return true;
    }

    private function __makeFullKey($strKey, $intLockMode){
        $strParentPath = $this->_getParentPath($this->strPath . '/' . $strKey);
        $strLockKey = $this->_getSelfName($strKey);
        $strPre = '';
        if($intLockMode == self::READ_LOCK){
            $strPre = $this->strRlockPre;
        }elseif($intLockMode == self::WRITE_LOCK){
            $strPre = $this->strWlockPre;
        }
        $strFullKey = $strParentPath . '/' . $strPre . '-' . $strLockKey . '-' ;
        return $strFullKey;
    }

    private function __getLockMode($strLockKey){
        $arrInfo = explode('-', $strLockKey);
        $intMode = null;
        if($arrInfo){
            if($arrInfo[0] == $this->strRlockPre){
                $intMode = self::READ_LOCK;
            }elseif($arrInfo[0] == $this->strWlockPre){
                $intMode = self::WRITE_LOCK;
            }
        }
        return $intMode;
    }
}
