<?php
/**
 *	Filemanager PHP class
 *
 *	filemanager.class.php
 *	class for the filemanager.php connector
 *
 *	@license	MIT License
 *	@author		Riaan Los <mail (at) riaanlos (dot) nl>
 *	@author		Simon Georget <simon (at) linea21 (dot) com>
 *	@copyright	Authors
 */

class Filemanager {

  protected $config = array();
  protected $language = array();
  protected $get = array();
  protected $post = array();
  protected $properties = array();
  protected $item = array();
  protected $languages = array();
  protected $root = '';
  protected $doc_root = '';
  protected $debug = false;

  public function __construct($config) {
  	
  	$content = file_get_contents("../../scripts/filemanager.config.js");
  	$config = json_decode($content, true);
  	
    $this->config = $config;
    
    $this->root = dirname(dirname(dirname(__FILE__))).DIRECTORY_SEPARATOR;
    $this->properties = array(
  	  	  'Date Created'=>null,
  	  	  'Date Modified'=>null,
  	  	  'Height'=>null,
  	  	  'Width'=>null,
  	  	  'Size'=>null
    );

    // if fileRoot is set manually, $this->doc_root takes fileRoot value
    // for security check in isValidPath() method
    // else it takes $_SERVER['DOCUMENT_ROOT'] default value
    if ($this->config['options']['fileRoot'] !== false ) {
    	$this->doc_root = $this->config['options']['fileRoot'];
    } else {
    	$this->doc_root = $_SERVER['DOCUMENT_ROOT'];
    }

    $this->setParams();
    $this->availableLanguages();
    $this->loadLanguageFile();
  }

  public function error($string,$textarea=false) {
    $array = array(
			'Error'=>$string,
			'Code'=>'-1',
			'Properties'=>$this->properties
    );
    if($textarea) {
      echo '<textarea>' . json_encode($array) . '</textarea>';
    } else {
      echo json_encode($array);
    }
    die();
  }

  public function lang($string) {
    if(isset($this->language[$string]) && $this->language[$string]!='') {
      return $this->language[$string];
    } else {
      return 'Language string error on ' . $string;
    }
  }

  public function getvar($var) {
    if(!isset($_GET[$var]) || $_GET[$var]=='') {
      $this->error(sprintf($this->lang('INVALID_VAR'),$var));
    } else {
      $this->get[$var] = $this->sanitize($_GET[$var]);
      return true;
    }
  }
  public function postvar($var) {
    if(!isset($_POST[$var]) || $_POST[$var]=='') {
      $this->error(sprintf($this->lang('INVALID_VAR'),$var));
    } else {
      $this->post[$var] = $_POST[$var];
      return true;
    }
  }

  public function getinfo() {
    $this->item = array();
    $this->item['properties'] = $this->properties;
    $this->get_file_info();

    $array = array(
			'Path'=> $this->get['path'],
			'Filename'=>$this->item['filename'],
			'File Type'=>$this->item['filetype'],
			'Preview'=>$this->item['preview'],
			'Properties'=>$this->item['properties'],
			'Error'=>"",
			'Code'=>0
    );
    return $array;
  }

  public function getfolder() {
    $array = array();
    $filesDir = array();

    $current_path = $this->getFullPath();
    
    // $this->__debug('$this->doc_root value in ' . __FUNCTION__ . ' : ' . $this->doc_root);
    // $this->__debug('$this->get[path] value in ' . __FUNCTION__ . ' : ' . $current_path);
    
    if(!$this->isValidPath($current_path)) {
    	$this->error("No way.");
    }
    
    if(!is_dir($current_path)) {
      $this->error(sprintf($this->lang('DIRECTORY_NOT_EXIST'),$this->get['path']));
    }
    if(!$handle = opendir($current_path)) {
      $this->error(sprintf($this->lang('UNABLE_TO_OPEN_DIRECTORY'),$this->get['path']));
    } else {
      while (false !== ($file = readdir($handle))) {
        if($file != "." && $file != "..") {
          array_push($filesDir, $file);
        }
      }
      closedir($handle);

      natcasesort($filesDir); // sorting by names

      // Sort files by modified time, latest to earliest
      // Use SORT_ASC in place of SORT_DESC for earliest to latest
      //      array_multisort(
      //        array_map( 'filemtime', $filesDir ),
      //        SORT_NUMERIC,
      //        SORT_DESC,
      //        $filesDir
      //      );

      foreach($filesDir as $file) {

        if(is_dir($current_path . $file)) {
          if(!in_array($file, $this->config['exclude']['unallowed_dirs']) && !preg_match( $this->config['exclude']['unallowed_dirs_REGEXP'], $file)) {
            $array[$this->get['path'] . $file .'/'] = array(
						'Path'=> $this->get['path'] . $file .'/',
						'Filename'=>$file,
						'File Type'=>'dir',
						'Preview'=> $this->config['icons']['path'] . $this->config['icons']['directory'],
						'Properties'=>array(
							'Date Created'=>null,
							'Date Modified'=>null,
							'Height'=>null,
							'Width'=>null,
							'Size'=>null
            ),
						'Error'=>"",
						'Code'=>0
            );
          }
        } else if (!in_array($file, $this->config['exclude']['unallowed_files'])  && !preg_match( $this->config['exclude']['unallowed_files_REGEXP'], $file)) {
          $this->item = array();
          $this->item['properties'] = $this->properties;
          $this->get_file_info($this->get['path'] . $file);
           
          if(!isset($this->params['type']) || (isset($this->params['type']) && strtolower($this->params['type'])=='images' && in_array(strtolower($this->item['filetype']),$this->config['images']['imagesExt']))) {
            if($this->config['upload']['imagesOnly']== false || ($this->config['upload']['imagesOnly']== true && in_array(strtolower($this->item['filetype']),$this->config['images']['imagesExt']))) {
              $array[$this->get['path'] . $file] = array(
							'Path'=>$this->get['path'] . $file,
							'Filename'=>$this->item['filename'],
							'File Type'=>$this->item['filetype'],
							'Preview'=>$this->item['preview'],
							'Properties'=>$this->item['properties'],
							'Error'=>"",
							'Code'=>0
              );
            }
          }
        }
      }
    }
    
    return $array;
  }

  public function rename() {

    $suffix='';


    if(substr($this->get['old'],-1,1)=='/') {
      $this->get['old'] = substr($this->get['old'],0,(strlen($this->get['old'])-1));
      $suffix='/';
    }
    $tmp = explode('/',$this->get['old']);
    $filename = $tmp[(sizeof($tmp)-1)];
    $path = str_replace('/' . $filename,'',$this->get['old']);
    
    $new_file = $this->getFullPath($path . '/' . $this->get['new']);
    $old_file = $this->getFullPath($this->get['old']);
    
    if(!$this->isValidPath($old_file)) {
    	$this->error("No way.");
    }
    
    $this->__debug('rename() : $new_file '. $new_file);
    $this->__debug('rename() : $old_file '. $old_file);
    $this->__debug('rename() : $this->get[new] '. $this->get['new']);
    $this->__debug('rename() : $this->get[old] '. $this->get['old']);

    if(file_exists ($new_file)) {
      if($suffix=='/' && is_dir($new_file)) {
        $this->error(sprintf($this->lang('DIRECTORY_ALREADY_EXISTS'),$this->get['new']));
      }
      if($suffix=='' && is_file($new_file)) {
        $this->error(sprintf($this->lang('FILE_ALREADY_EXISTS'),$this->get['new']));
      }
    }

    if(!rename($old_file,$new_file)) {
      if(is_dir($old_file)) {
        $this->error(sprintf($this->lang('ERROR_RENAMING_DIRECTORY'),$filename,$this->get['new']));
      } else {
        $this->error(sprintf($this->lang('ERROR_RENAMING_FILE'),$filename,$this->get['new']));
      }
    }
    $array = array(
			'Error'=>"",
			'Code'=>0,
			'Old Path'=>$this->get['old'],
			'Old Name'=>$filename,
			'New Path'=>$path . '/' . $this->get['new'].$suffix,
			'New Name'=>$this->get['new']
    );
    return $array;
  }

  public function delete() {

  	$current_path = $this->getFullPath();
  	
  	if(!$this->isValidPath($current_path)) {
  		$this->error("No way.");
  	}
  	
    if(is_dir($current_path)) {
      $this->unlinkRecursive($current_path);
      $array = array(
				'Error'=>"",
				'Code'=>0,
				'Path'=>$this->get['path']
      );
      return $array;
    } else if(file_exists($current_path)) {
      unlink($current_path);
      $array = array(
				'Error'=>"",
				'Code'=>0,
				'Path'=>$this->get['path']
      );
      return $array;
    } else {
      $this->error(sprintf($this->lang('INVALID_DIRECTORY_OR_FILE')));
    }
  }

  public function add() {
  	
    $this->setParams();
    
    if(!isset($_FILES['newfile']) || !is_uploaded_file($_FILES['newfile']['tmp_name'])) {
      $this->error(sprintf($this->lang('INVALID_FILE_UPLOAD')),true);
    }
    if(($this->config['upload']['size']!=false && is_numeric($this->config['upload']['size'])) && ($_FILES['newfile']['size'] > ($this->config['upload']['size'] * 1024 * 1024))) {
      $this->error(sprintf($this->lang('UPLOAD_FILES_SMALLER_THAN'),$this->config['upload']['size'] . 'Mb'),true);
    }
    if($this->config['upload']['imagesOnly'] || (isset($this->params['type']) && strtolower($this->params['type'])=='images')) {
      if(!($size = @getimagesize($_FILES['newfile']['tmp_name']))){
        $this->error(sprintf($this->lang('UPLOAD_IMAGES_ONLY')),true);
      }
      if(!in_array($size[2], array(1, 2, 3, 7, 8))) {
        $this->error(sprintf($this->lang('UPLOAD_IMAGES_TYPE_JPEG_GIF_PNG')),true);
      }
    }
    $_FILES['newfile']['name'] = $this->cleanString($_FILES['newfile']['name'],array('.','-'));
    
    $current_path = $this->getFullPath($this->post['currentpath']);
    
    if(!$this->isValidPath($current_path)) {
    	$this->error("No way.");
    }
    
    if(!$this->config['upload']['overwrite']) {
      $_FILES['newfile']['name'] = $this->checkFilename($current_path,$_FILES['newfile']['name']);
    }
    move_uploaded_file($_FILES['newfile']['tmp_name'], $current_path . $_FILES['newfile']['name']);
    chmod($current_path . $_FILES['newfile']['name'], 0644);

    $response = array(
			'Path'=>$this->post['currentpath'],
			'Name'=>$_FILES['newfile']['name'],
			'Error'=>"",
			'Code'=>0
    );
    echo '<textarea>' . json_encode($response) . '</textarea>';
    die();
  }

  public function addfolder() {
  	
  	$current_path = $this->getFullPath();
  	
  	if(!$this->isValidPath($current_path)) {
  		$this->error("No way.");
  	}
    if(is_dir($current_path . $this->get['name'])) {
      $this->error(sprintf($this->lang('DIRECTORY_ALREADY_EXISTS'),$this->get['name']));
       
    }
    $newdir = $this->cleanString($this->get['name']);
    if(!mkdir($current_path . $newdir,0755)) {
      $this->error(sprintf($this->lang('UNABLE_TO_CREATE_DIRECTORY'),$newdir));
    }
    $array = array(
			'Parent'=>$this->get['path'],
			'Name'=>$this->get['name'],
			'Error'=>"",
			'Code'=>0
    );
    return $array;
  }

  public function download() {
  	
  	$current_path = $this->getFullPath();
  	
  	if(!$this->isValidPath($current_path)) {
  		$this->error("No way.");
  	}

    if(isset($this->get['path']) && file_exists($current_path)) {
      header("Content-type: application/force-download");
      header('Content-Disposition: inline; filename="' . basename($current_path) . '"');
      header("Content-Transfer-Encoding: Binary");
      header("Content-length: ".$current_path);
      header('Content-Type: application/octet-stream');
      header('Content-Disposition: attachment; filename="' . basename($current_path) . '"');
      readfile($current_path);
      exit();
    } else {
      $this->error(sprintf($this->lang('FILE_DOES_NOT_EXIST'),$current_path));
    }
  }

  public function preview() {
  	
  	$current_path = $this->getFullPath();
  	
    if(isset($this->get['path']) && file_exists($current_path)) {
      header("Content-type: image/" .$ext = pathinfo($current_path, PATHINFO_EXTENSION));
      header("Content-Transfer-Encoding: Binary");
      header("Content-length: ".filesize($current_path));
      header('Content-Disposition: inline; filename="' . basename($current_path) . '"');
      readfile($current_path);
      exit();
    } else {
      $this->error(sprintf($this->lang('FILE_DOES_NOT_EXIST'),$current_path));
    }
  }
  
  public function getMaxUploadFileSize() {
  	
  	$max_upload = (int) ini_get('upload_max_filesize');
		$max_post = (int) ini_get('post_max_size');
		$memory_limit = (int) ini_get('memory_limit');
		
		$upload_mb = min($max_upload, $max_post, $memory_limit);
		
		return $upload_mb;
  }

  private function setParams() {
    $tmp = (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/');
    $tmp = explode('?',$tmp);
    $params = array();
    if(isset($tmp[1]) && $tmp[1]!='') {
      $params_tmp = explode('&',$tmp[1]);
      if(is_array($params_tmp)) {
        foreach($params_tmp as $value) {
          $tmp = explode('=',$value);
          if(isset($tmp[0]) && $tmp[0]!='' && isset($tmp[1]) && $tmp[1]!='') {
            $params[$tmp[0]] = $tmp[1];
          }
        }
      }
    }
    $this->params = $params;
  }


  private function get_file_info($path='',$return=array()) {
  	
  	// DO NOT  rawurlencode() since $current_path it
  	// is used for displaying name file
    if($path=='') {
      $current_path = $this->get['path'];
    } else {
    	$current_path = $path;
    }
    $tmp = explode('/',$current_path);
    $this->item['filename'] = $tmp[(sizeof($tmp)-1)];

    $tmp = explode('.',$this->item['filename']);
    $this->item['filetype'] = $tmp[(sizeof($tmp)-1)];
    $this->item['filemtime'] = filemtime($current_path);
    $this->item['filectime'] = filectime($current_path);

    $this->item['preview'] = $this->config['icons']['path'] . $this->config['icons']['default'];

    if(is_dir($current_path)) {
       
      $this->item['preview'] = $this->config['icons']['path'] . $this->config['icons']['directory'];
       
    } else if(in_array(strtolower($this->item['filetype']),$this->config['images']['imagesExt'])) {

      $this->item['preview'] = 'connectors/php/filemanager.php?mode=preview&path='. rawurlencode($current_path);
      //if(isset($get['getsize']) && $get['getsize']=='true') {
      $this->item['properties']['Size'] = filesize($current_path);
      if ($this->item['properties']['Size']) {
        list($width, $height, $type, $attr) = getimagesize($current_path);
      } else {
        $this->item['properties']['Size'] = 0;
        list($width, $height) = array(0, 0);
      }
      $this->item['properties']['Height'] = $height;
      $this->item['properties']['Width'] = $width;
      $this->item['properties']['Size'] = filesize($current_path);
      //}
       
    } else if(file_exists($this->root . $this->config['icons']['path'] . strtolower($this->item['filetype']) . '.png')) {
       
      $this->item['preview'] = $this->config['icons']['path'] . strtolower($this->item['filetype']) . '.png';
      $this->item['properties']['Size'] = filesize($current_path);
      if (!$this->item['properties']['Size']) $this->item['properties']['Size'] = 0;
       
    }

    $this->item['properties']['Date Modified'] = date($this->config['options']['dateFormat'], $this->item['filemtime']);
    //$return['properties']['Date Created'] = $this->config['options']['dateFormat'], $return['filectime']); // PHP cannot get create timestamp
  }
  
  private function getFullPath($path = '') {
  	
  	if($path == '') {
  		$path = $this->get['path'];
  	}

  	// $this->__debug('getCurrentPath : $this->doc_root ' .$this->doc_root. '   $this->get[path] : ' . $path . '');
  	
  	if($this->config['options']['fileRoot'] !== false) {
  		$full_path = $this->doc_root . rawurldecode(str_replace ( $this->doc_root , '' , $path));
  	} else {
  		$full_path = $this->doc_root . rawurldecode($path);
  	}
  	
  return $full_path;
  	
  }
  
  private function isValidPath($path) {
  	
  	// @todo remove debug message
  	// $this->__debug('compare : ' .$this->getFullPath(). '($this->getFullPath())  and ' . $path . '(path)');
  	// $this->__debug('strncmp() retruned value : ' .strncmp($path, $this->getFullPath(), strlen($this->getFullPath())));
  	
  	return !strncmp($path, $this->getFullPath(), strlen($this->getFullPath()));
  	 
  }

  private function unlinkRecursive($dir,$deleteRootToo=true) {
    if(!$dh = @opendir($dir)) {
      return;
    }
    while (false !== ($obj = readdir($dh))) {
      if($obj == '.' || $obj == '..') {
        continue;
      }
       
      if (!@unlink($dir . '/' . $obj)) {
        $this->unlinkRecursive($dir.'/'.$obj, true);
      }
    }

    closedir($dh);

    if ($deleteRootToo) {
      @rmdir($dir);
    }
    return;
  }

  private function cleanString($string, $allowed = array()) {
    $allow = null;

    if (!empty($allowed)) {
      foreach ($allowed as $value) {
        $allow .= "\\$value";
      }
    }

    $mapping = array(
        'Š'=>'S', 'š'=>'s', 'Đ'=>'Dj', 'đ'=>'dj', 'Ž'=>'Z', 'ž'=>'z', 'Č'=>'C', 'č'=>'c', 'Ć'=>'C', 'ć'=>'c',
        'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
        'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O',
        'Õ'=>'O', 'Ö'=>'O', 'Ő'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ű'=>'U', 'Ý'=>'Y',
        'Þ'=>'B', 'ß'=>'Ss','à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c',
        'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n',
        'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ő'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'ű'=>'u',
        'û'=>'u', 'ý'=>'y', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y', 'Ŕ'=>'R', 'ŕ'=>'r', ' '=>'_', "'"=>'_', '/'=>''
        );

        if (is_array($string)) {

          $cleaned = array();

          foreach ($string as $key => $clean) {
            $clean = strtr($clean, $mapping);
            $clean = preg_replace("/[^{$allow}_a-zA-Z0-9]/", '', $clean);
            $cleaned[$key] = preg_replace('/[_]+/', '_', $clean); // remove double underscore
          }
        } else {
          $string = strtr($string, $mapping);
          $string = preg_replace("/[^{$allow}_a-zA-Z0-9]/", '', $string);
          $cleaned = preg_replace('/[_]+/', '_', $string); // remove double underscore
        }
        return $cleaned;
  }

  private function sanitize($var) {
    $sanitized = strip_tags($var);
    $sanitized = str_replace('http://', '', $sanitized);
    $sanitized = str_replace('https://', '', $sanitized);
    $sanitized = str_replace('../', '', $sanitized);
    return $sanitized;
  }

  private function checkFilename($path,$filename,$i='') {
    if(!file_exists($path . $filename)) {
      return $filename;
    } else {
      $_i = $i;
      $tmp = explode(/*$this->config['upload']['suffix'] . */$i . '.',$filename);
      if($i=='') {
        $i=1;
      } else {
        $i++;
      }
      $filename = str_replace($_i . '.' . $tmp[(sizeof($tmp)-1)],$i . '.' . $tmp[(sizeof($tmp)-1)],$filename);
      return $this->checkFilename($path,$filename,$i);
    }
  }

  private function loadLanguageFile() {

    // we load langCode var passed into URL if present and if exists
    // else, we use default configuration var
    $lang = $this->config['options']['culture'];
    if(isset($this->params['langCode']) && in_array($this->params['langCode'], $this->languages)) $lang = $this->params['langCode'];

    if(file_exists($this->root. 'scripts/languages/'.$lang.'.js')) {
      $stream =file_get_contents($this->root. 'scripts/languages/'.$lang.'.js');
      $this->language = json_decode($stream, true);
    } else {
      $stream =file_get_contents($this->root. 'scripts/languages/'.$lang.'.js');
      $this->language = json_decode($stream, true);
    }
  }

  private function availableLanguages() {

    if ($handle = opendir($this->root.'/scripts/languages/')) {
      while (false !== ($file = readdir($handle))) {
        if ($file != "." && $file != "..") {
          array_push($this->languages, pathinfo($file, PATHINFO_FILENAME));
        }
      }
      closedir($handle);
    }
  }
  
  private function __debug($msg) {
  	
  	if($this->debug == true) {
	  	$filename = "/tmp/filemanager_debug.txt";
	  	$fd = fopen($filename, "a");
	  	$str = "[" . date("d/m/Y h:i:s", mktime()) . "] " . $msg;
	  	fwrite($fd, $str . PHP_EOL);
	  	fclose($fd);
  	}
  	
  }
}
?>