<?php

if (!function_exists('oss_substr')) {
    function oss_substr($string, $start, $length = null) {
        if ($string === null || $string === false) {
            $string = '';
        }
        if ($length === null) {
            return substr($string, $start);
        }
        return substr($string, $start, $length);
    }
}

if (!function_exists('oss_strlen')) {
    function oss_strlen($string) {
        if ($string === null || $string === false) {
            return 0;
        }
        return strlen($string);
    }
}

// Ensure the adapter is loaded
// require_once('OU_ALIOSS_Adapter.php'); // This will be loaded by oss-upload.php

final class OSSWrapper extends OU_ALIOSS {
	private $position = 0, $mode = '', $buffer;

    // Ensure compatibility with PHP 8.x
    // Removed private $buffer; redeclaration if it was inherited, but it wasn't.

	private function safeSubstr($string, $start, $length = null) {
        $string = $string ?? '';
        if ($length === null) {
            return substr($string, $start);
        }
        return substr($string, $start, $length);
    }

    private function safeStrlen($string) {
        return strlen($string ?? '');
    }

	public function url_stat($path, $flags) {
		$return = false;
        // Optimization: check if it's a directory by path ending?
        // Old logic checked list_objects.
		$info = self::dir_opendir($path, array('osdir' => 1, 'max-keys' => 1));
		if(!empty($info['is_folder'])){
			$return = array('mode' => 16895);
		}else{
			if(!empty($info['is_file']) && !empty($info['file_info'])){
				$return = $info['file_info'];
				$return['mode'] = 33279;
			}
		}
		clearstatcache();
		return $return;
	}

	public function unlink($path) {
		self::__getURL($path);
		$info = self::delete_object($this->url['host'], $this->url['path']);
		clearstatcache(true);
		return $info->isOK();
	}

	public function mkdir($path, $mode, $options) {
		self::__getURL(rtrim($path,'/'));
		$info = self::create_object_dir($this->url['host'], $this->url['path']);
		return $info->isOK();
	}

	public function rmdir($path) {
		self::__getURL($path);
		$info = self::delete_object($this->url['host'], $this->url['path']);
		clearstatcache(true);
		return $info->isOK();
	}

	public function rename($path, $to) {
		self::__getURL($path);
        $tourl = parse_url($to);
        $tourl['path'] = isset($tourl['path']) ? $this->safeSubstr($tourl['path'], 1) : '';
        // Host might be different? Assuming same bucket for simplicity or parsing $to correctly.
        // If $to is oss://bucket/path
        $toBucket = isset($tourl['host']) ? $tourl['host'] : $this->url['host'];

		$info = self::copy_object($this->url['host'], $this->url['path'], $toBucket, $tourl['path']);
		if($info->isOK()) $info = self::delete_object($this->url['host'], $this->url['path']);
		clearstatcache(true);
		return $info->isOK();
	}

	public function dir_opendir($path, $options) {
		self::__getURL($path);
		if(empty($options)) $options = array();
		$osdir = isset($options['osdir']);
		$options['prefix'] = $osdir ? $this->url['path'] : rtrim($this->url['path'], '/').'/';

        // This relies on the adapter's list_object returning JSON
		$info = self::list_object($this->url['host'], $options);

		if($info->isOK()){
			$is_folder = false;
			$is_file = false;
			$file_info = false;

            $data = json_decode($info->body, true);

            // Check if we are at root or empty prefix
            $prefix = isset($data['prefix']) ? $data['prefix'] : '';
            // The JSON from adapter puts 'is_legacy_adapter' => true

            // Allow empty prefix (root)
			if(!$options['prefix']){
				if(!empty($options['osdir'])){
					return array('is_folder' => true, 'is_file' => $is_file, 'file_info' => $file_info);
				}
			}

			$this->buffer = array();

            // Handle CommonPrefixes (Folders)
            $prefixList = isset($data['prefix_list']) ? $data['prefix_list'] : array();
			if(!empty($prefixList)){
				$is_folder = true;
                foreach ($prefixList as $p) {
                    $prefixVal = isset($p['Prefix']) ? $p['Prefix'] : '';
                    if($key = $this->safeSubstr($prefixVal, strlen($options['prefix']))) $this->buffer[] = $key;
                }
			}

            // Handle Contents (Files)
            $objectList = isset($data['object_list']) ? $data['object_list'] : array();
			if(!empty($objectList)){
				$is_file = true;
                foreach ($objectList as $o) {
                    $keyVal = isset($o['Key']) ? $o['Key'] : '';
                    if($this->safeSubstr($keyVal, -1) == '/'){ // Directory marker object
						$is_folder = true;
						$is_file = false;
					}

                    if($key = $this->safeSubstr($keyVal, strlen($options['prefix']))) $this->buffer[] = $key;

                    // Populate file info for url_stat (only relevant if single file check?)
                    // url_stat passes max-keys=1.
                    if (isset($options['max-keys']) && $options['max-keys'] == 1) {
                        $file_info = array(
                            'size' => isset($o['Size']) ? $o['Size'] : 0,
                            'atime' => strtotime(isset($o['LastModified']) ? $o['LastModified'] : 'now'),
                            'mtime' => strtotime(isset($o['LastModified']) ? $o['LastModified'] : 'now'),
                            'ctime' => strtotime(isset($o['LastModified']) ? $o['LastModified'] : 'now')
                        );
                    }
                }
			}

			if(!empty($options['osdir'])){
				return array('is_folder' => $is_folder, 'is_file' => $is_file, 'file_info' => $file_info);
			}

			if(!empty($this->buffer)){
				// Make unique and re-index?
                $this->buffer = array_values(array_unique($this->buffer));
				$this->position = 0;
				// unset($this->buffer); // Wait, if I unset it, how do I read it? The old code unset it?
                // Old code: return true; if buffer not empty.
                // Old code Logic:
                /*
                if(!empty($this->buffer)){
                    $this->position = 0;
                    unset($this->buffer); // This looks like a bug in old code or logic I misunderstood?
                    // Ah, dir_opendir calls list_object.
                    // But dir_readdir reads from $this->buffer.
                    // If unset here, readdir will fail.
                    // Actually, stream_wrapper dir_opendir should returns true on success.
                */
                // I will NOT unset it.
				return true;
			}else{
				return $is_folder || $is_file;
			}
			return true;
		}
		return false;
	}

	public function dir_readdir() {
		return (isset($this->buffer[$this->position])) ? $this->buffer[$this->position++] : false;
	}

	public function dir_rewinddir() {
		$this->position = 0;
	}

	public function dir_closedir() {
		$this->position = 0;
		unset($this->buffer);
	}

	public function stream_stat() {
		if (is_object($this->buffer) && isset($this->buffer->headers)){
			return array(
				'size' => $this->buffer->headers['size'],
				'mtime' => $this->buffer->headers['time'],
				'ctime' => $this->buffer->headers['time']
			);
		}else{
			$info = self::get_object_meta($this->url['host'], $this->url['path']);
            // The adapter returns headers in $info->header

			$size = isset($info->header['_info']['download_content_length']) ? $info->header['_info']['download_content_length'] : 0;
			if(empty($size)) $size = isset($info->header['content-length']) ? $info->header['content-length'] : 0;
			if($info->isOK()) return array(
				'size' => $size,
				'atime' => isset($info->header['_info']['filetime']) ? $info->header['_info']['filetime'] : 0,
				'mtime' => isset($info->header['_info']['filetime']) ? $info->header['_info']['filetime'] : 0,
				'ctime' => isset($info->header['_info']['filetime']) ? $info->header['_info']['filetime'] : 0
			);
		}
		return false;
	}

	public function stream_open($path, $mode, $options, &$opened_path) {
		if (!in_array($mode, array('r', 'rb', 'w', 'wb'))) return false;
		$this->mode = $this->safeSubstr($mode, 0, 1);
		self::__getURL($path);
		$this->position = 0;
		if ($this->mode == 'r') {
			if (($this->buffer = self::get_object($this->url['host'], $this->url['path'])) !== false) {
				if (is_object($this->buffer->body)) $this->buffer->body = (string)$this->buffer->body;
                // Adapter returns OU_Response, body is string content
                $this->buffer = $this->buffer->body;
			} else return false;
		} else {
            $this->buffer = ''; // Initialize buffer for writing
        }
		return true;
	}

	public function stream_read($count) {
	    if ($this->mode !== 'r' && $this->buffer !== false) return false;

        $buffer = $this->buffer; // It's a string now
        $buffer = $buffer ?? '';

        $position = (int)$this->position;
        $count = (int)$count;

        $data = $this->safeSubstr($buffer, $position, $count);
        $this->position += strlen($data);
        return $data;
	}

	public function stream_write($data) {
		if ($this->mode !== 'w') return 0;
        // Basic in-memory buffer for write.
        // Warning: This stores entire file in memory. Large files will consume RAM.
		$left = $this->safeSubstr($this->buffer, 0, $this->position);
		$right = $this->safeSubstr($this->buffer, $this->position + strlen($data));
		$this->buffer = $left . $data . $right;
		$this->position += strlen($data);
		return strlen($data);
	}

	public function stream_close() {
		if ($this->mode == 'w') {
			$options = array(
			    'content' => $this->buffer,
			    'length' => strlen($this->buffer)
			);
			self::upload_file_by_content($this->url['host'], $this->url['path'], $options);
		}
		$this->position = 0;
		unset($this->buffer);
	}

	public function stream_seek($offset, $whence) {
        $len = strlen($this->buffer ?? '');
		switch ($whence) {
			case SEEK_SET:
                if ($offset < $len && $offset >= 0) {
                    $this->position = $offset;
                    return true;
                } else return false;
            break;
            case SEEK_CUR:
                if ($this->position + $offset >= 0) {
                    $this->position += $offset;
                    return true;
                } else return false;
            break;
            case SEEK_END:
                if ($len + $offset >= 0) {
                    $this->position = $len + $offset;
                    return true;
                } else return false;
            break;
            default: return false;
        }
    }

	public function stream_tell() {
		return $this->position;
	}

	public function stream_eof() {
		$buffer = $this->buffer;
        $buffer = $buffer ?? '';
        return $this->position >= strlen($buffer);
	}

	public function stream_flush() {
		$this->position = 0;
		return true;
	}

    public function stream_metadata($path, $options, $var) {
    	//http://php.net/manual/en/streamwrapper.stream-metadata.php
        return true;
    }

	public function stream_truncate($new_size) {
        // Not implemented in original fully?
        $this->buffer = substr($this->buffer, 0, $new_size);
		return true;
	}

    private function __getURL($path) {
        $this->url = parse_url($path);
        if(!isset($this->url['scheme']) || $this->url['scheme'] !== 'oss') return $this->url;

        // This calls the OU_ALIOSS_Adapter's setAuth, ensuring we use defined constants.
        // Note: OSSWrapper inherits __construct, but stream usage doesn't call it.
        // We explicitly call setAuth here.
        if(defined('OSS_ACCESS_ID') && defined('OSS_ACCESS_KEY')) {
            self::setAuth(OSS_ACCESS_ID, OSS_ACCESS_KEY);
        }
        $this->url['path'] = isset($this->url['path']) ? $this->safeSubstr($this->url['path'], 1) : '';
    }
}
stream_wrapper_register('oss', 'OSSWrapper');
