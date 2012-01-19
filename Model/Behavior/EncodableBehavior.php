<?php
App::uses('Zencoder', 'Encoders.Model');

/**
 * @todo		This behavior should handle encoding only, not the file management.  Thats what the media plugin is for.
 */
class EncodableBehavior extends ModelBehavior {


	var $type = 'Zencoder';
	var $userData = array();

	var $supportedVideoExtensions = array('mpg', 'mov', 'wmv', 'rm', '3g2', '3gp', '3gp2', '3gpp', '3gpp2', 'avi', 'divx', 'dv', 'dv-avi', 'dvx', 'f4v', 'flv', 'h264', 'hdmov', 'm4v', 'mkv', 'mp4', 'mp4v', 'mpe', 'mpeg', 'mpeg4', 'mpg', 'nsv', 'qt', 'swf', 'xvid');
	var $supportedAudioExtensions = array('aif', 'mid', 'midi', 'mka', 'mp1', 'mp2', 'mp3', 'mpa', 'wav', 'aac', 'flac', 'ogg', 'ra', 'raw', 'wma');


	function setup(&$model, $settings = array()) {
		$this->type = !empty($settings['type']) ? $settings['type'] : $this->type;
	}


/**
 * @todo			This needs to be broken up into a few smaller functions.  (like handle the moving of files separately, and so on.
 */
	function encode(&$model) { #override fields before Media model saves it
		$uuid = $model->_generateUUID(); /** @todo Perhaps this should be in the Encoder Model instead.. */
		#debug($model->data);break;

		$supportedExtensions = array_merge($this->supportedVideoExtensions, $this->supportedAudioExtensions);
		if(!empty($model->data['Media']['filename']['size'])) {
			unset($model->data['Media']['submittedurl']);
			$uploadedFile = true;
			$fileExtension = $this->getFileExtension($model->data['Media']['filename']['name']);

			if(!in_array($fileExtension, $supportedExtensions)) {
				return false;
			}
			#debug($model->data['Media']['submittedfile']); break;
		} elseif($model->data['Media']['submittedurl']) {
			unset($model->data['Media']['filename']);
			$fileExtension = $this->getFileExtension($model->data['Media']['submittedurl']);
			if(!in_array($this->getFileExtension($model->data['Media']['submittedurl']), $supportedExtensions)) {
				return false;
			}
			#debug($model->data['Media']['submittedurl']); break;
		} else {
			return false;
		}

		#debug($model->data);break;
		// this will be the base of the filename of the media file that we store locally and send to the encoder
		$model->data['Media']['safeFileName'] = $uuid;
		$model->data['Media']['id'] = $uuid;
		

		if(!empty($uploadedFile)) {
			// create new filetype-based upload directory if it doesn't exist and save the uploaded file locally
			$uploadDirectory = ROOT.DS.SITE_DIR.DS.'View'.DS.'Themed'.DS.'Default'.DS.WEBROOT_DIR . DS . 'media' . DS . strtolower(ZuhaInflector::pluginize($model->data['Media']['model'])). DS . $model->data['Media']['type'];
			if(!is_dir($uploadDirectory)) {
				mkdir($uploadDirectory, 0777);
			}
			$fileSavedLocally = rename($model->data['Media']['filename']['tmp_name'], $uploadDirectory . DS . $uuid.'.'.$fileExtension);

			// set the Public URL of the local file so that the encoder can download it
			$model->data['Media']['publicMediaFilePath'] = 'http://' . $_SERVER['HTTP_HOST'] . '/theme/default/media/'.strtolower(ZuhaInflector::pluginize($model->data['Media']['model'])).'/' .  $model->data['Media']['type'] . '/' . $uuid.'.'.$fileExtension;

		} else {
			// set the Public URL of the remote file so that the encoder can download it
			$model->data['Media']['publicMediaFilePath'] = $model->data['Media']['submittedurl'];
		}
		
		$model->data['Media']['filename'] = $model->data['Media']['safeFileName'];
		$model->data['Media']['extension'] = $fileExtension;


		if(!empty($fileSavedLocally)) {
			# set the Media.type (audio|video)
			if(in_array($fileExtension, $this->supportedAudioExtensions)) { 
				$mediaType = 'audio';
			} elseif(in_array($fileExtension, $this->supportedVideoExtensions)) {
				$mediaType = 'videos';
			}
			$model->data['Media']['type'] = $mediaType;
			

			# send the file to the encoder
			$encoder = new $this->type();
			$response = $encoder->save($model->data);

			//debug($response);
			# set the data we received from the encoder
			$model->data['Media']['zen_job_id'] = $response['id'];
			if(!empty($response['outputs'][1]) ) {
				# we have multiple output formats
				$model->data['Media']['zen_output_id'] = !empty($model->data['Media']['zen_output_id']) ? $model->data['Media']['zen_output_id'] : null;
				foreach($response['outputs'] as $output) {
					$model->data['Media']['zen_output_id'] .= $output['id'] . ',';
                    // ... or ...
					$outputs[] = array('label' => $output['label'], 'zen_output_id' => $output['id']);
				}
				$model->data['Media']['zen_output_id'] = rtrim($model->data['Media']['zen_output_id'], ',');
				$model->data['Media']['filename'] = json_encode(array('outputs' => $outputs));
				
			} elseif(!empty($response['outputs'][0])) {
				# we just have one output format
				$model->data['Media']['zen_output_id'] = $response['outputs'][0]['id'];
				// ... or ...
				$outputs = array('label' => $response['outputs'][0]['label'], 'zen_output_id' => $response['outputs'][0]['id']);
				$model->data['Media']['filename'] = json_encode(array('outputs' => $outputs));
			} else {
				# the response was empty, but save data anyway (for now)
				$model->data['Media']['filename'] = $uuid;
			}

           # debug($model->data);break;
			return $model->data;
            
		} elseif(!empty($model->data['Media']['submittedurl'])) {

			# send the file to the encoder
			$encoder = new $this->type();
			$response = $encoder->save($model->data);

			return $model->data;
		} else {
			return $model->data;
		}

	}//beforeSave()



    function getFileExtension($filepath) {
        preg_match('/[^?]*/', $filepath, $matches);
        $string = $matches[0];

        $pattern = preg_split('/\./', $string, -1, PREG_SPLIT_OFFSET_CAPTURE);

        # check if there is any extension
        if(count($pattern) == 1)
        {
            return FALSE;
        }

        if(count($pattern) > 1)
        {
            $filenamepart = $pattern[count($pattern)-1][0];
            preg_match('/[^?]*/', $filenamepart, $matches);
            return strtolower($matches[0]);
        }
    }



    function getFileName($filepath) {
        preg_match('/[^?]*/', $filepath, $matches);
        $string = $matches[0];
        #split the string by the literal dot in the filename
        $pattern = preg_split('/\./', $string, -1, PREG_SPLIT_OFFSET_CAPTURE);
        #get the last dot position
        $lastdot = $pattern[count($pattern)-1][1];
        #now extract the filename using the basename function
        $filename = basename(substr($string, 0, $lastdot-1));
        #return the filename part
        return $filename;
    }



}//class{}