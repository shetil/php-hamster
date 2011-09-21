<?php
/**
 * Cli tool for adding stuff to the hamster timetracking database
 * Run php-hamster from command line to see all options
 * 
 * @author Kjetil Wikestad
 */
class Hamster_Cli{
    /**
     *
     * @var Zend_Db_Abstract
     */
    private $_db;
    private $_defaults = array();
    public static $_cfg_options = 'm:s:e:a:t:d:f:';
    public static $_cfg_longopt = array('test','config:','help','author:');
    private $_options;
   
    public function __construct($config,$options) {
             
        $this->_db = Zend_Db::factory("pdo_sqlite", array("dbname"=>$config['db']));
        
        if(isset($config['defaults']) && is_array($config['defaults'])){
            $this->_defaults =  $config['defaults'];
        }
        
        $this->_options = array_merge(
                            $this->_defaults,
                            $options
                            );
        
    }
    
    public function run(){
        
        $action = $this->_options['m'];
        
        if(!$action || !isset($this->_options['a']) || isset($this->_options['help'])){
            $this->actionHelp();
            return;
        }
        
        $method = 'action'.ucfirst($action);
        
        if(method_exists($this, $method)){
            
            $this->$method();
            
        } else {
            
            throw new Exception('No method has been set. See: php-hamster --help');
            
        }
        
    }
   
    public function actionAdd(){
        
        $this->store($this->_options);
    }
    
    public function actionGitlog(){
        
        if(!isset($this->_options['f']) || !file_exists($this->_options['f'])){
            
            $log  = file_get_contents('php://stdin');
            
            if(!$log){
                throw new Exception('No log to parse!');
            }
                        
        } else {
            
            $log = file_get_contents($this->_options['f']);
            
        }
        
        $this->parseGitLog($log);
        
    }
    
    private function store(array $entry){
       
        $this->_db->beginTransaction();
        
        $fact = $this->parseFact($entry);
        $activity = $this->parseActivity($entry);
        $tags = $this->parseTags($entry);
     
        $fact['activity_id'] = $activity['id'];
        
        $this->insert('facts',$fact);
        
        $fact['id'] = $this->_db->lastInsertId();
        
        foreach((array)$tags as $tag){
            $this->insert('fact_tags',array(
               'fact_id'=>$fact['id'],
               'tag_id'=>$tag['id']
            ));
        }
        
        if($this->isTest() == true){
            print $this->arrToStr(array('fact'=>$fact,'activity'=>$activity,'tags'=>$tags));
        }
        
        $this->_db->commit();
    }
   
    private function storeAll(array $entries){
        
        foreach($entries as $entry){
            $this->store($entry);
        }
        
    }
   
    private function insert($table, array $bind){
        
        if($this->isTest() == false){
            $this->_db->insert($table, $bind);    
        }
    }
    
    private function isTest(){
        return isset($this->_options['test']);
    }
    
    private function parseGitLog($log){

        $arrLog = preg_split('/commit [a-z0-9]{40}\n/', $log);
        $commits = array();

        foreach($arrLog as $commit){
            if(!$commit) continue;

            $matches = array();

            $result = preg_match("/Date:\s+(.*)\n\n(.*)/", $commit,$matches);

            if(isset($this->_options['author'])){
                $auth_found = preg_match("/Author:\s+{$this->_options['author']}(.*)/",$commit);
                if($auth_found == false){
                    continue;
                }
            }
            
            if(!$result){
                throw new Exception('Invalid commit entry: '.$commit);
            }

            $store = array(
                's'=>$matches[1],
                'e'=>$matches[1],
                'd'=>trim($matches[2])
            );

            $store = array_merge($this->_options,$store);
            $commits[] = $store;
        }
        
        $this->storeAll($commits);
    }
    
    private function parseFact($entry){
        
        $s = strtotime($entry['s']);
        $e = strtotime($entry['e']);
        
        if(!$s){
            throw new Exception("Invalid datetime: ".$s);
        }
    
        return array(
            'start_time'=>date('Y-m-d H:i:s',$s),
            'end_time'=>date('Y-m-d H:i:s',$e),
            'description'=>$entry['d'],
            'activity_id'=>null
        );
        
    }
    
    private function parseTags($entry){
        $tags = null;
        
        if(!isset($entry['t'])){
            return $tags;
        }
        
        $arr = explode(',',$entry['t']);
        
        foreach($arr as $str){
            $row = $this->_db->fetchRow('select * from tags where name = ?',$str);
            
            if(!$row){
                $row =  array(
                   'name'=>$str,
                   'autocomplete'=>true
                );
                
                $this->insert('tags',$row);
                
                $row['id'] = $this->_db->lastInsertId();
            }
            
            $tags[] = $row;
        }
        
        return $tags;
    }
    
    private function parseActivity($entry){
        $activity = null;
        $category = null;
        
        $a = split('@',$entry['a']);
      
        if(isset($a[1])){
            
            $query = $this->_db->select()
                          ->from('categories')
                          ->where('name = ?',$a[1])
                          ->orWhere('search_name = ?',$a[1]);
            
            $category = $this->_db->fetchRow($query);

            if(!$category){
                throw new Exception('Could not find category: '.$a[1]);
            }
        }
        
      
        $query = $this->_db->select()
                      ->from('activities')
                      ->where('name = ?',$a[0])
                      ->orWhere('search_name = ?',$a[0]);
        
        if($category){
            $query->where('category_id = ?',$category['id']);
        } else {
            $query->where('category_id = null');
        }
        
        $activity = $this->_db->fetchRow($query);
        
        if(!$activity){
            throw new Exception('Could not find activity: '.$a[0]);
        }
        
        if($category){
            $activity['category'] = $category;
        }
        
        
        return $activity;
    }

    private function arrToStr(array $var,$tab=0){
        if($tab == 0){
            $str = "\n---Entry---\n";
        } else {
            $str = '';
        }
        
        
        $indent = str_repeat("    ",$tab);
        
        foreach($var as  $key=>$value){
            if(is_array($value) == false){
                $str .=   $indent."$key: $value\n";
            } else {
                $str .= $indent."$key: [\n";
                $str .= $this->arrToStr($value,++$tab);
                $str .= $indent."]\n";
            }
        }
        
        return $str;
    }
    
    public function actionHelp(){
        
        print
        print_r("
    How to use php-hamster:  

    -m  Method (either 'add' or 'gitlog')
    -a  Activity (activity@category)
    -s  Start Datetime
    -e  End Datetime
    -d  Description
    -t  Tag (seperated by comma)
    -f  File. It also possible to use stdin.
    

    --author use this to only except commits from a specific author in the gitlog
    --test Will print instead of inserting into database
    --config path to config file (defaults to config.php in php-hamster directory
    --help This text
\n");
        
    }
    
}