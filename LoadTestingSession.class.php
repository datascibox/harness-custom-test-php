<?php

/* Copyright: RedLine13.com . */

require_once('LoadTestingPageResponse.class.php');

/**
 * Load Testing Session is the CURL wrapper.  This is not required but does provide some easy calling and managing of web requests. 
 *
 * CURL Options Set by Default include
 * CURLOPT_RETURNTRANSFER (1)
 * CURLOPT_HEADER (0) - Do not include headers in output
 * CURLOPT_FOLLOWLOCATION (1) - Follow Redirects
 * CURLOPT_FRESH_CONNECT (0) - Do not use persist connections.
 * CURLOPT_ENCODING ('') - Enable compression
 * CURLOPT_CONNECTTIMEOUT (30) - 30 second time out
 * CURLOPT_TIMEOUT (120) - Overall timeout
 * CURLOPT_COOKIEJAR - Setup location for cookie jar.
 * CURLOPT_SSL_VERIFYPEER (0) - Don't validate SSL
 */
class LoadTestingSession
{
	/** Test Number */
	private $testNum = null;

	/** Random token for test */
	private $rand = null;

	/** Cookie directory */
	private $cookieDir = null;

	/** Output directory */
	private $outputDir = null;

	/** Flags */
	private $flags = 0;

	/** Load Resources Flag */
	const LOAD_RESOURCES = 0x00000001;

	/** Verbose Flag */
	const VERBOSE = 0x00000002;

	/** Curl Handle */
	private $ch = null;

	/** Cookie jar (i.e. filename) */
	private $cookieJar = null;

	/** Last Response Headers */
	private $lastRespHeaders = array();

	/** Last URL */
	private $lastUrl = null;

	/** Min. delay (in ms) after fetching page */
	private $minDelay = 0;
	/** Max. delay (in ms) after fetching page */
	private $maxDelay = 0;
	/** Track last used delay. */
	private $delay = null;

	/** Resource Cache */
	private $resourceCache = array();

	/** Resource Data */
	private $resourceData = array();

	/** Base URL that resources will be loaded for */
	public $loadableResourceBaseUrl = null;

	/**
	 * Constructor
	 * @param int $testNum Test number
	 * @param string $rand Random token for test
	 * @param string $cookieDir Cookie directory (default: 'cookies')
	 * @param string $outputDir Output directory (default: 'output')
	 */
	public function __construct($testNum, $rand, $cookieDir = 'cookies', $outputDir = 'output')
	{
		$this->testNum = $testNum;
		$this->rand = $rand;
		$this->cookieDir = $cookieDir;
		$this->outputDir = $outputDir;

		// Seed random number generate
		srand($testNum * time());

		// Set up curl
		$this->ch = curl_init();

		// Setup curl_exec to return output
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, 1);

		// Don't include HTTP headers in output
		curl_setopt($this->ch, CURLOPT_HEADER, 0);

		// Set up function to get headers
		curl_setopt($this->ch, CURLOPT_HEADERFUNCTION, array($this,'getLastCurlRespHeaders'));

		// Include request header in curl info
		curl_setopt($this->ch, CURLINFO_HEADER_OUT, 1);

		// Follow redirects
		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, 1);

		// Don't use persistent connection
		// curl_setopt($this->ch, CURLOPT_FORBID_REUSE, 1);
		curl_setopt($this->ch, CURLOPT_FRESH_CONNECT, 1);

		// Enable compression
		curl_setopt($this->ch, CURLOPT_ENCODING, '');

		// Timeouts
		curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($this->ch, CURLOPT_TIMEOUT, 120);

		// Set cookie jar
		$this->cookieJar = tempnam($this->cookieDir, 'cookie-');
		curl_setopt($this->ch, CURLOPT_COOKIEJAR, $this->cookieJar);

		// Don't check SSL
		curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, 0);
	}

	/** __get */
	public function __get($name)
	{
		if (property_exists($this, $name))
			return $this->$name;
		return null;
	}

	/** Enable resource loading */
	public function enableResourceLoading() {
		$this->flags |= self::LOAD_RESOURCES;
	}

	/** Disable resource loading */
	public function disableResourceLoading() {
		$this->flags &= ~self::LOAD_RESOURCES;
	}

	/** Verbose */
	public function verbose() {
		$this->flags |= self::VERBOSE;

		// Output cookie jar file
		echo 'Cookie jar: ' . $this->cookieJar . PHP_EOL;
	}

	/** Non-verbose */
	public function nonVerbose() {
		$this->flags &= ~self::VERBOSE;
	}

	/**
	 * Set delay (in ms) on page load.
	 * @param int $minDelay Min. artificial delay (in ms) on page load.
	 * @param int $maxDelay Max. artificial delay (in ms) on page load.
	 */
	public function setDelay($minDelay, $maxDelay)
	{
		if ($minDelay > $maxDelay)
		{
			$swap = $minDelay;
			$minDelay = $maxDelay;
			$maxDelay = $swap;
		}
		$this->minDelay = $minDelay;
		$this->maxDelay = $maxDelay;
		if ($this->maxDelay === null)
			$this->maxDelay = $this->minDelay;
	}

	/**
	 * Send back recently used delay value.
	 */
	public function getDelay()
	{
		return $this->delay;
	}

	/** Cleanup */
	public function cleanup()
	{
		// Close curl
		curl_close($this->ch);

		// Delete cookie file
		if ($this->cookieJar)
			unlink($this->cookieJar);
	}

	/**
	 * Set last curl response headers
	 */
	public function getLastCurlRespHeaders($ch, $header)
	{
		$this->lastRespHeaders[] = trim($header);
		return strlen($header);
	}

	/**
	 * Fetch raw data form a URL with no delays or output
	 * @param string $url URL to go to
	 * @param mixed $post POST data, or null
	 * @param array $headers HTTP headers to send
	 * @param bool $saveData True to save data to file system
	 * @return string Raw response
	 * @throws Exception
	 */
	public function fetchRawDataFromUrl($url, $post = null, $headers = array(), $saveData = false)
	{
		// Set referer
		if ($this->lastUrl != null)
			curl_setopt($this->ch, CURLOPT_REFERER, $this->lastUrl);
		$this->lastUrl = $url;

		// Set post info
		if (!empty($post))
		{
			curl_setopt($this->ch, CURLOPT_POST, 1);
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, $post);
		}
		else
			curl_setopt($this->ch, CURLOPT_POST, 0);

		// Set up headers
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);

		// Get page
		curl_setopt($this->ch, CURLOPT_URL, $url);
		$this->lastRespHeaders = array();	// Clear last response headers
		if (($content = curl_exec($this->ch)) === false)
			throw new Exception(curl_error($this->ch));

		// Save data
		if ($saveData)
		{
			static $pageNum = 0;
			$pageNum++;
			file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . 'test'.$this->testNum.'-rawData'.$pageNum.'-info.txt', print_r(curl_getinfo($this->ch), 1).print_r($this->lastRespHeaders, 1));
			file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . 'test'.$this->testNum.'-rawData'.$pageNum.'-content.txt', $content);
		}

		return $content;
	}

	/**
	 * Remove query string and fragment from URL
	 *
	 * @param string $url URL
	 *
	 * @return string URL without query string and fragment
	 */
	public function removeQueryStringAndFragmentFromUrl($url)
	{
		$pos = strrpos($url, '?');
		if ($pos !== false)
			return substr($url, 0, $pos);
		$pos = strrpos($url, '#');
		if ($pos !== false)
			return substr($url, 0, $pos);
		return $url;
	}

	/**
	 * Go to a url.
	 * @param string $url URL to go to
	 * @param mixed $post POST data, or null
	 * @param array $headers HTTP headers to send
	 * @param boolean $saveData writing it to file.
	 * @return LoadTestingPageResponse
	 * @throws Exception
	 */
	public function goToUrl($url, $post = null, $headers = array(), $saveData = false, $isUser = true )
	{
		// Add slight delay to simulate user delay
		if ($this->minDelay || $this->maxDelay)
		{
			$delay = rand($this->minDelay*1000, $this->maxDelay*1000);
			$this->delay = ($delay/1000000);
			if ($this->flags & self::VERBOSE)
				echo 'Delay: ' . ($delay/1000000) . "s\n";
			usleep($delay);
		}

		// Set referer
		if ($this->lastUrl != null)
			curl_setopt($this->ch, CURLOPT_REFERER, $this->lastUrl);
		$this->lastUrl = $url;

		$rtn = new LoadTestingPageResponse();

		// Set post info
		if (!empty($post))
		{
			curl_setopt($this->ch, CURLOPT_POST, 1);
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, $post);
		}
		else
			curl_setopt($this->ch, CURLOPT_POST, 0);

		// Set up headers
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);

		// Get page
		if ($this->flags & self::VERBOSE)
			echo date('m/d/Y G:i:s') . PHP_EOL;
		curl_setopt($this->ch, CURLOPT_URL, $url);
		$this->lastRespHeaders = array();	// Clear last response headers
		$startTime = time();
		if (($content = curl_exec($this->ch)) === false){
			$endTime = time();
			$totalTime = $endTime - $startTime;
			if ( $isUser ){
				recordPageTime($endTime, $totalTime, true, 0);
			}
			recordURLPageLoad($this->removeQueryStringAndFragmentFromUrl($url), $endTime, $totalTime, true, 0);
			throw new Exception(curl_error($this->ch));
		}
		$curlInfo = curl_getinfo($this->ch);
		$rtn->setContent($content, $curlInfo['content_type']);
		// Get info
		$rtn->setInfo($curlInfo);
		if ($this->flags & self::VERBOSE)
			echo date('m/d/Y G:i:s') . PHP_EOL;

		// Record bandwidth
		$info = $rtn->getInfo();
		$kb = 0;
		if ($info['download_content_length'] > 0){
			$kb = (int)$info['download_content_length']/1024;
		}
		else if ($info['size_download'] > 0){
			$kb = (int)$info['size_download']/1024;
		}

		// Check response code
		$respCode = $rtn->getHttpStatus();
		$respError = false;
		if (!($respCode >= 200 && $respCode <= 399))
			$respError = true;

		// Record info
		$endTime = time();
		$totalTime = $rtn->getTotalTime();
		if ( $isUser ){
			recordPageTime($endTime, $totalTime, $respError, 0);
		}
		recordURLPageLoad($this->removeQueryStringAndFragmentFromUrl($url), $endTime, $totalTime,$respError,$kb);

		// Save files
		if ($saveData)
		{
			static $pageNum = 0;
			$pageNum++;
			file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . 'test'.$this->testNum.'-page'.$pageNum.'-info.txt', print_r($rtn->getInfo(), 1).print_r($this->lastRespHeaders, 1));
			file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . 'test'.$this->testNum.'-page'.$pageNum.'-content.html', $rtn->getContent());
		}

		// Load resources
		if ( !$respError && $this->flags & self::LOAD_RESOURCES)
			$this->loadResources($rtn);

		return $rtn;
	}

	/**
	 * Parse page and request other resources
	 * @param LoadTestingPageResponse $page Page object
	 */
	public function loadResources(LoadTestingPageResponse $page)
	{
		// TODO: Allow all resources?
		if ($this->loadableResourceBaseUrl)
		{
			$resources = array();

			// Get CSS hrefs
			foreach ($page->getCssHrefs() as $href)
				if (strpos($href, $this->loadableResourceBaseUrl) === 0)
					$resources[] = $href;

			// Get image hrefs
			foreach ($page->getImageHrefs() as $href)
				if (strpos($href, $this->loadableResourceBaseUrl) === 0)
					$resources[] = $href;

			// Get javascript srcs
			foreach ($page->getJavascriptSrcs() as $src)
				if (strpos($src, $this->loadableResourceBaseUrl) === 0)
					$resources[] = $src;

			// Set up new curl object
			if ( !empty( $resources ) && $ch = curl_init())
			{
				// Set options
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_HEADER, 1);
				//curl_setopt($ch, CURLINFO_HEADER_OUT, 1);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

				// Use persistent connection
				//curl_setopt($ch, CURLOPT_MAXCONNECTS, 4);
				curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
				curl_setopt($ch, CURLOPT_FRESH_CONNECT, 0);

				// Enable compression
				curl_setopt($ch, CURLOPT_ENCODING, '');

				// Timeouts
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
				curl_setopt($ch, CURLOPT_TIMEOUT, 120);

				// Set cookie jar
				curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieJar);

				// Don't check SSL
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

				// Get each resource
				$numInCache = $num304s = 0;
				foreach ($resources as $resource)
				{
					// Check if this is in the cache
					if (isset($this->resourceCache[$resource]) && $this->resourceCache[$resource] > time())
						$numInCache++;
					else
					{
						// Set up headers
						$headers = array(
							'Cache-Control:max-age=0',
							'Connection: keep-alive',
							'Keep-Alive: 300'
						);

						// Check if we have a last modified
						if (isset($this->resourceData[$resource]['Last-Modified']))
							$headers[] = 'If-Modified-Since: ' . $this->resourceData[$resource]['Last-Modified'];
						// Check if we have an ETag
						if (isset($this->resourceData[$resource]['ETag']))
							$headers[] = 'If-None-Match: ' . $this->resourceData[$resource]['ETag'];

						// Set headers
						curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

						// Make request
						curl_setopt($ch, CURLOPT_URL, $resource);
						$startTime = time();
						if (($content = curl_exec($ch)) === false){
							$endTime = time();
							recordPageTime($endTime, 0, true, 0);
							recordURLPageLoad($this->removeQueryStringAndFragmentFromUrl($resource),$endTime,0,true,0);
							throw new Exception(curl_error($ch));
						}

						// Check status code
						$info = curl_getinfo($ch);
						if ((int)$info['http_code'] == 304)
							$num304s++;

						// Record bandwidth
						$kb = 0;
						if ($info['download_content_length'] > 0){
							$kb = (int)$info['download_content_length']/1024;
						}
						else if ($info['size_download'] > 0){
							$kb = (int)$info['size_download']/1024;
						}

						// Check response code
						$respCode = !empty($info['http_code']) ? (int)$info['http_code'] : null;
						$respError = false;
						if (!($respCode >= 200 && $respCode <= 399)){
							$respError = true;
							$err = array("uri" => $this->removeQueryStringAndFragmentFromUrl($resource), "code" => $respCode);
							recordError(json_encode($err));
						}

						// Record load
						$endTime = time();
						recordURLPageLoad($this->removeQueryStringAndFragmentFromUrl($resource), $endTime, !empty($info['total_time']) ? (float)$info['total_time'] : 0.0, $respError, $kb );

						// Add resource data
						if (!isset($this->resourceData[$resource]))
						{
							$this->resourceData[$resource] = array(
								'Last-Modified' => null,
								'ETag'
							);
						}

						// Read headers
						$lines = preg_split("/(\r?\n)|\r/", $content);
						array_shift($lines);
						foreach($lines as $line)
						{
							if (empty($line))
								break;
							//if ($this->flags & self::VERBOSE)
							//echo $line . PHP_EOL;

							// Parse header
							if (!preg_match('/^([^:]+):(.*)$/AD', $line, $match))
								throw new Exception("{$resource}: Bad header \"{$line}\"");
							$header = strtolower(trim($match[1]));
							$value = strtolower(trim($match[2]));

							// Check for cache headers
							$expires = null;
							if ($header == 'cache-control' && preg_match('/max-age=([0-9]+)/AD', $value, $match))
								$expires = time()+$match[1];
							else if ($header == 'expires')
								$expires = strtotime($value);
							if ($expires)
								$this->resourceCache[$resource] = $expires;

							// Other headers
							if ($header == 'last-modified')
								$this->resourceData[$resource]['Last-Modified'] = $value;
							if ($header == 'etag')
								$this->resourceData[$resource]['ETag'] = $value;
						}
					}
				}
				if ($this->flags & self::VERBOSE)
					echo "Page requires " . count($resources) . " resources for base domain.  {$numInCache} found in cache.  {$num304s} 304 Not Modified responses.\n";

				// Close curl
				curl_close($ch);
			}
		}
	}

	/**
	 * Get automatic value for form field
	 * @param string $name Field name
	 * @return string Value, or null
	 */
	public function getFormAutoValue($name)
	{
		$lowerAlpha = 'abcdefghijklmnopqrstuvwxyz';

		// Names
		if (preg_match('/first.*name/i', $name))
			return 'Load';
		elseif (preg_match('/last.*name/i', $name))
			return 'Test' . $this->testNum;
		// E-mails
		else if (preg_match('/email/i', $name))
			return $lowerAlpha[rand(0,25)].$lowerAlpha[rand(0,25)].'-LoadTest' . $this->testNum . '-' . time() .'@example.com';
		// Passwords
		else if (preg_match('/password/i', $name))
			return 'password';
		// Address
		else if (preg_match('/address/i', $name))
			return $this->testNum . ' Load Test Ave.';
		// City
		else if (preg_match('/city/i', $name))
			return 'Moorestown';
		// State
		else if (preg_match('/country/i', $name))
			return 'US';
		// State
		else if (preg_match('/state/i', $name))
			return 'NJ';
		// Zip code
		else if (preg_match('/zipcode/i', $name))
			return '08057';
		// Dob (Random)
		else if (preg_match('/dob/i', $name))
			return rand(1,12) . '/' . rand(1,28) . '/' . rand(1950, 2005);
		// Phone (Random)
		else if (preg_match('/phone/i', $name))
			return rand(100, 999) . '-555-' . sprintf('%04d', rand(0, 9999));
		// Phone (Random)
		else if (preg_match('/gender/i', $name))
			return rand(0,1) ? 'M' : 'F';
		// Credit card info
		else if (preg_match('/cardNumber/i', $name))
			return '4111111111111111';
		else if (preg_match('/cvv/i', $name))
			return '123';
		else if (preg_match('/cardExpiresMonth/i', $name))
			return rand(1,12);
		else if (preg_match('/cardExpiresYear/i', $name))
			return 2050;

		return null;
	}
}

?>
