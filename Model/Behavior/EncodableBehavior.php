<?php
App::uses('Zencoder', 'Encoders.Model');
class EncodableBehavior extends ModelBehavior {


	var $type = 'Zencoder';
	var $userData = array();
	
	var $Supported_Video_Extensions = array('mpg', 'mov', 'wmv', 'rm', '3g2', '3gp', '3gp2', '3gpp', '3gpp2', 'avi', 'divx', 'dv', 'dv-avi', 'dvx', 'f4v', 'flv', 'h264', 'hdmov', 'm4v', 'mkv', 'mp4', 'mp4v', 'mpe', 'mpeg', 'mpeg4', 'mpg', 'nsv', 'qt', 'swf', 'xvid');
	var $Supported_Audio_Extensions = array('aif', 'mid', 'midi', 'mka', 'mp1', 'mp2', 'mp3', 'mpa', 'wav', 'aac', 'flac', 'ogg', 'ra', 'raw', 'wma');
	

	function setup(&$model, $settings = array()) {
		$this->type = !empty($settings['type']) ? $settings['type'] : $this->type;
	}

	function beforeSave(&$model) {
		#override fields before Media model saves it
		
		#debug($model->data);break;
		
		$Supported_Extensions = array_merge($this->Supported_Video_Extensions, $this->Supported_Audio_Extensions);
		
		if($model->data['Media']['submittedfile']['size'] > 0) {
			unset($model->data['Media']['submittedurl']);
			$uploaded_file = true;
			
			if(!in_array(getFileExtension($model->data['Media']['submittedfile']['name']), $Supported_Extensions)) return false;
			
			#debug($model->data['Media']['submittedfile']); break;
		} elseif($model->data['Media']['submittedurl']) {
			unset($model->data['Media']['submittedfile']);
			
			if(!in_array(getFileExtension($model->data['Media']['submittedurl']), $Supported_Extensions)) return false;
			
			#debug($model->data['Media']['submittedurl']); break;
		} else {
			return false;
		}
		
		#debug($model->data);break;
		
		if($uploaded_file) {
			$SafeFileName = $model->data['Media']['submittedfile']['name'];
		} else {
			$SafeFileName = getFileName($model->data['Media']['submittedurl']) . getFileExtension($model->data['Media']['submittedurl']);
			$model->data['Media']['publicMediaFilePath'] = $model->data['Media']['submittedurl'];
		}
		
			
		// rename file
		$SafeFileName = str_replace("#", "No.", $SafeFileName);
		$SafeFileName = str_replace("$", "Dollar", $SafeFileName);
		$SafeFileName = str_replace("%", "Percent", $SafeFileName);
		$SafeFileName = str_replace("^", "", $SafeFileName);
		$SafeFileName = str_replace("&", "and", $SafeFileName);
		$SafeFileName = str_replace("*", "", $SafeFileName);
		$SafeFileName = str_replace("?", "", $SafeFileName);
		
		$model->data['Media']['SafeFileName'] = getFileName($SafeFileName);
		#$model->data['Media']['file_extension'] = getFileExtension($SafeFileName);
		
		if($uploaded_file) {
			move_uploaded_file($model->data['Media']['submittedfile']['tmp_name'], SITE_DIR . DS . 'media' . DS . 'uploads' . DS . $SafeFileName);
			$model->data['Media']['publicMediaFilePath'] = 'http://' . $_SERVER['HTTP_HOST'] . '/theme/default/media/uploads/' . getFileExtension($SafeFileName) . '/' . $SafeFileName;
		}
		
		
		$encoder = new $this->type();
		$model->data['filename'] = $encoder->save($model->data);
		
		return true;
	}
	
	
	/**
	 * Update the find methods so that we check against the used table that the current user is part of this item being searched.
	 * @todo	I'm sure we will need some checks and stuff added to this.  (Right now this Project is Used, so make sure Project works if you change this function.)
	 */
	function beforeFind(&$model, $queryData) {
		$userRole = CakeSession::read('Auth.User.user_role_id');
		$userId = CakeSession::read('Auth.User.id');
		//if ($userRole != 1) : 
			// temporary until we find a better way
			# this allows you to bypass the logged in user check (nocheck should equal the user id)
			$userQuery = !empty($queryData['nocheck']) ? "Used.user_id = {$queryData['nocheck']}" : "Used.user_id = {$userId}";
			# output the new query
			$queryData['joins'] = array(array(
				'table' => 'used',
				'alias' => 'Used',
				'type' => 'INNER',
				'conditions' => array(
				"Used.foreign_key = {$model->alias}.id",
				"Used.model = '{$model->alias}'",
				$userQuery,
				),
			));
		//endif;
		return $queryData;
	}
	
	/**
	 * Callback used to save related users, into the used table, with the proper relationship.
	 */
	function afterSave(&$Model, $created) {		
		# this is if we have a hasMany list of users coming in.
		if (!empty($Model->data['User'][0])) :
			foreach ($Model->data['User'] as $user) :
				#$users[]['id'] = $user['user_id']; // before cakephp 2.0 upgrade
				$users[]['id'] = $user['id'];
			endforeach;
		endif;
		
		# this is if we have a habtm list of users coming in.
		if (!empty($this->userData['User']['User'][0])) :
			foreach ($this->userData['User']['User'] as $userId) :
				$users[]['id'] = $userId;
			endforeach;
		endif;
		
		# this is if its a user group we need to look up.
		if (!empty($Model->data[$Model->alias]['user_group_id'])) :
			# add all of the team members to the used table 
			$userGroups = $Model->UserGroup->find('all', array(
				'conditions' => array(
					'UserGroup.id' => $Model->data[$Model->alias]['user_group_id'],
					),
				'contain' => array(
					'User',
					),
				));
			foreach ($userGroups as $userGroup) :
				if(!empty($userGroup['User'])) : 
					$users = !empty($users) ? array_merge($userGroup['User'], $users) : $userGroup['User'];
				endif;
			endforeach;
		endif;
		
		if (!empty($users)) :
			$i=0;
			foreach ($users as $user) : 
				$data[$i]['Used']['user_id'] = $user['id'];
				$data[$i]['Used']['foreign_key'] = $Model->id;
				$data[$i]['Used']['model'] = $Model->alias;
				$data[$i]['Used']['role'] = $this->defaultRole; // this is temporary, until we start doing real acl 
				$i++;
			endforeach;
			
			$Used = ClassRegistry::init('Users.Used');
			foreach ($data as $dat) : 
				$Used->create();
				$Used->save($dat);
			endforeach;
		endif;
	}
	


    function getFileExtension($filepath) 
    { 
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
    
    function getFileName($filepath) 
    { 
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