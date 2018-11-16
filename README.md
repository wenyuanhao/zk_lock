基于zookeeper 实现的分布式阻塞锁和共享锁，原理有时间再写。。  
demo:  
$arrHosts = [  
    '172.17.0.10:2181',  
    '172.17.0.11:2181',  
    '172.17.0.12:2181',  
];  
//xlock  
$objZkXlock = new ZkXlock($arrHosts, 5);  
$objZkXlock->setLockPath('/lockpath');   
$strLockKey = $objZkXlock->lock('lockname', 10);  
$objZkXlock->unlock($strLockKey);  
  
//slock  
$objZkXlock = new ZkSlock($arrHosts, 5);  
$objZkXlock->setLockPath('/lockpath');   
//write lock  
$strLockKey = $objZkXlock->wlock('lockname', 10);  
//read lock  
$strLockKey = $objZkXlock->rlock('lockname', 10);  
$objZkXlock->unlock($strLockKey);   
