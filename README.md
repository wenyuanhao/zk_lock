����zookeeper ʵ�ֵķֲ�ʽ�������͹�������ԭ����ʱ����д����  
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
