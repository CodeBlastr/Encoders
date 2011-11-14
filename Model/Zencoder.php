<?PHP
/*
*
*
*	https://app.zencoder.com/docs
*/

App::uses('HttpSocket', 'Network/Http');

class Zencoder extends AppModel {
	
	public $name = 'Zencoder';
	var $useTable = false;
	
	var $_API_KEY = 'd7e9c0967186d3f2ee6f98a9e10ad0db';
	var $_ZENDCODER_URL = 'https://app.zencoder.com/api/v2/'; //('http://ec2-107-22-67-64.compute-1.amazonaws.com/'); # zencoder's debug server

	
	public function __construct($config = null) {
		#parent::__construct($config);
		$this->connection = new HttpSocket();
		
	}//__construct()
	
	
	/*	Toggle between LIVE and INTEGRATION modes
	*	https://app.zencoder.com/docs/api/accounts/integration
	*/
	public function setIntegrationMode($mode) {
		
		$url = ($mode == 'live') ? 'account/live' : 'account/integration';
		$url .= '?api_key=' . $this->_API_KEY;
		$response = json_decode($this->connection->get($url), true);
		
		return $response;
		
	}//setIntegrationMode()(
	
	
	/*	https://app.zencoder.com/docs/api/jobs/create
	*/
	public function save($data) {
		#debug($data);break;
		
		$_MEDIA_SERVER = Configure::read('Media.mediaserver');
		$integrationMode = Configure::read('Media.integrationMode');
		
		$requestParams = array( // see: https://app.zencoder.com/docs/api/encoding/general-output-settings
			# REQUIRED
			'api_key' => $this->_API_KEY, // The API key for your Zencoder account.
			'input' => $data['Media']['publicMediaFilePath'], // A S3, Cloud Files, FTP, FTPS, or SFTP URL where we can download file to transcode.
			# OPTIONAL
			'region' => 'us', // The region where a file is processed: US, Europe, or Asia.
			'private' => 'false', // Enable privacy mode for a job.
			'download_connections' => '5', // Utilize multiple, simultaneous connections for download acceleration (in some circumstances).
			'pass_through' => '', // Optional information to store alongside this job.
			'grouping' => '', // A report grouping for this job.
			'notifications' => array('format' => 'json', 'url' => 'http://' . $_SERVER['HTTP_HOST'] . '/media/media/notification')
		);
		
		if($integrationMode == TRUE) {
			$requestParams['mock'] = 'true'; // Send a mocked job request. (return data but don't process)
			$requestParams['test'] = 1; // Enable test mode ("Integration Mode") for a job.	
		}

		if($data['Media']['type'] == 'V') {
			
			$_MEDIA_SERVER .= ROOT.DS.SITE_DIR.DS.'View'.DS.'Themed'.DS.'Default'.DS.WEBROOT_DIR.DS.'media'.DS . 'streams'. DS .'video' . DS ;
			
			$requestParams['outputs'] = array( // An array or hash of output settings.
					array( // output version 1
						'label' => 'web',
						'url' => $_MEDIA_SERVER . $data['Media']['SafeFileName'] . '.mp4' // destination of the encoded file
					),
	//				array( // output version 2
	//					'label' => 'dvd',
	//				),
	//				array( // output version 3
	//					'label' => 'mobile'
	//				)
			);

		
		} elseif($data['Media']['type'] == 'A') {
			
			$_MEDIA_SERVER .= ROOT.DS.SITE_DIR.DS.'View'.DS.'Themed'.DS.'Default'.DS.WEBROOT_DIR.DS.'media'.DS.'streams' . DS .'audio' . DS ;

			$requestParams['outputs'] = array( // An array or hash of output settings.
					array( // output version 1
						'label' => 'web',
						'url' => $_MEDIA_SERVER . $data['Media']['SafeFileName'] . '.mp3' // destination of the encoded file
					),
	//				array( // output version 2
	//					'label' => 'dvd',
	//				),
	//				array( // output version 3
	//					'label' => 'mobile'
	//				)
			);

		}
		
		$url = 'jobs';
		
		$requestParams = json_encode($requestParams);
		#debug($requestParams);
		$response = $this->connection->post($this->_ZENDCODER_URL . $url, $requestParams, array('header' => array('Content-Type' => 'application/json')));
		#debug($response);
		
		if($response->code == '201') {
			// job created.  Should return: JSON {  "id": "1234",  "outputs": [    {      "id": "4321"    }  ]}
			return json_decode($response->body, true);
			
		} else {
			#debug($response);
			return FALSE;
		}
		
	}//createJob()
	
		
	/*	A list of jobs can be obtained by sending an HTTP GET request to https://app.zencoder.com/api/jobs?api_key=_YOUR_API_KEY_ 
	*	It will return an array of jobs.
	*	The list of thumbnails will be empty until the job is completed.
	*	By default, the results are paginated with 50 jobs per page and sorted by ID in descending order. You can pass two parameters to control the paging: page and per_page. per_page has a limit of 50.
	*
	*	https://app.zencoder.com/docs/api/jobs/list
	*/
	public function listJobs() {
		
		$url = 'jobs.json?api_key=' . $this->_API_KEY;
		$response = json_decode($this->connection->get($this->_ZENDCODER_URL . $url), true);
		
		return $response;
		
	}//listjobs()
	
	
	/* Job states include pending, waiting, processing, finished, failed, and cancelled.
	*  Input states include pending, waiting, processing, finished, failed, and cancelled.
	*  Output states include waiting, queued, assigning, processing, finished, failed, cancelled and no input.
	*
	*	https://app.zencoder.com/docs/api/jobs/show
	*/
	public function getJobDetails($jobID) {
		
		$url = 'jobs/' . $jobID . '.json?api_key=' . $this->_API_KEY;
		$response = json_decode($this->connection->get($this->_ZENDCODER_URL . $url), true);
		
		return $response;
		
	}//getJobDetails()
	
	
	/*	If a job has failed processing you may request that it be attempted again.
	*   This is useful if the job failed to process due to a correctable problem.
	*   You may resubmit a job for processing by sending a request (using any HTTP method) to https://app.zencoder.com/api/jobs/1234/resubmit?api_key=_YOUR_API_KEY_ 
	*   If resubmission succeeds you will receive a 200 OK response.
	*   Only jobs that are not in the "finished" state may be resubmitted. If you attempt to resubmit a "finished" job you will receive a 409 Conflict response.
	*
	*	https://app.zencoder.com/docs/api/jobs/resubmit
	*/
	public function resubmitJob($jobID) {
		
		$url = 'jobs/'. $jobID .'/resubmit.json?api_key=' . $this->_API_KEY;
		$response = $this->connection->get($this->_ZENDCODER_URL . $url);
		
		return $response;
		
	}//resubmitJob()
	
	
	/*	If you wish to cancel a job that has not yet finished processing you may send a request (using any HTTP method) to https://app.zencoder.com/api/jobs/1234/cancel.
	*	If cancellation succeeds you will receive a 200 OK response.
	*	Only jobs that are in the "waiting" or "processing" state may be cancelled.  If you attempt to cancel a job in any other state you will receive a 409 Conflict response.
	*
	*	https://app.zencoder.com/docs/api/jobs/cancel
	*/
	public function cancelJob($jobID) {
		
		$url = 'jobs/' . $jobID . '/cancel.json?api_key=' . $this->_API_KEY;
		$response = $this->connection->post($this->_ZENDCODER_URL . $url);
		
		return $response;
		
	}//cancelJob()
	
	
	/*	If you wish to delete a job entirely you may send an HTTP DELETE request to https://app.zencoder.com/api/jobs/1234?api_key=93h630j1dsyshjef620qlkavnmzui3.
	*	If deletion succeeds you will receive a 200 OK response.
	*	Only jobs that are not in the "finished" state may be deleted. If you attempt to delete a "finished" job you will receive a 409 Conflict response.
	*
	*	https://app.zencoder.com/docs/api/jobs/delete
	*/
	public function deleteJob($jobID) {
		
		$url = 'jobs/' . $jobID . '?api_key=' . $this->_API_KEY;
		$response = $this->connection->delete($this->_ZENDCODER_URL . $url);
		
		return $response;
		
	}//deleteJob()
	
	
	/*	https://app.zencoder.com/docs/api/inputs/show
	*/
	public function getInputDetails($inputID) {
		
		$url = 'inputs/' . $inputID . '.json?api_key=' . $this->_API_KEY;
		$response = json_decode($this->connection->get($this->_ZENDCODER_URL . $url), true);
		
		return $response;
		
	}//getInputDetails()
	
	
	/*	The return will contain one or more of the following keys: state, current_event, and progress.
	*	Valid states include: Waiting, Pending, Assigning, Processing, Finished, Failed, Cancelled.
	*	Events include: Downloading and Inspecting.
	*	The progress number is the percent complete of the current event – so if the event is Downloading, and progress is 99.3421, then the file is almost finished downloading, but hasn't started Inspecting yet.
	*
	*	Don't use for AJAX... we should use a different, read-only URL for that.
	*
	*	https://app.zencoder.com/docs/api/inputs/progress
	*/
	public function getInputProgress($inputID) {
		
		$url = 'inputs/' . $inputID . '/progress.json?api_key=' . $this->_API_KEY;
		$response = json_decode($this->connection->get($this->_ZENDCODER_URL . $url), true);
		
		return $response;
		
	}//getInputProgress()
	
	
	/*	https://app.zencoder.com/docs/api/outputs/show
	*/
	public function getOutputDetails($outputID) {
		
		$url = 'outputs/' . $inputID . '.json?api_key=' . $this->_API_KEY;
		$response = json_decode($this->connection->get($this->_ZENDCODER_URL . $url), true);
		
		return $response;
		
	}//getOutputDetails()
	
	
	/*	Valid states include: Waiting, Queued, Assigning, Processing, Finished, Failed, Cancelled and No Input.
	*	Events include: Inspecting, Downloading, Transcoding and Uploading.
	*	The progress number is the percent complete of the current event – so if the event is Transcoding, and progress is 99.3421, then the file is almost finished transcoding, but hasn't started Uploading yet.
	*
	*	https://app.zencoder.com/docs/api/outputs/progress
	*/
	public function getOutputProgress($outputID) {
		
		$url = 'outputs/' . $inputID . '/progress.json?api_key=' . $this->_API_KEY;
		$response = json_decode($this->connection->get($this->_ZENDCODER_URL . $url), true);
		
		return $response;
		
	}//getOutputProgress()
	
	
	/*	This report returns a breakdown of minute usage by day and grouping.
	*	It will contain two top-level keys: total and statistics.
	*	total will contain the sum of all statistics returned in the report.
	*	statistics will contain an entry for each day and grouping.
	*	If you don't use the report grouping feature of the API the report will contain only one entry per day.
	*	These statistics are collected about once per hour, but there is only one record per day (per grouping).
	*	By default this report excludes the current day from the response because it's only partially complete.
	*/
	public function getMinutesUsed($conditions) {
		
		$url = 'reports/minutes?api_key=' . $this->_API_KEY;
		if(!empty($conditions['from'])) $url .= '&from=' . $conditions['from'];
		if(!empty($conditions['to'])) $url .= '&to=' . $conditions['to'];
		if(!empty($conditions['from'])) $url .= '&grouping=' . $conditions['grouping'];
		
		$response = json_decode($this->connection->get($this->_ZENDCODER_URL . $url), true);
		
		return $response;
		
	}//getMinutesUsed()
	
	
}//class{}