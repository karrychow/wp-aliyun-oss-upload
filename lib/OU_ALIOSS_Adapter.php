<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 尝试加载 Guzzle (如果环境未提供)
if (!class_exists('GuzzleHttp\Client')) {
    // 优先加载 PHAR 文件，其中包含了所有依赖
    if (file_exists(dirname(__FILE__) . '/alibabacloud-oss-php-sdk-v2-0.4.0.phar')) {
        require_once dirname(__FILE__) . '/alibabacloud-oss-php-sdk-v2-0.4.0.phar';
    } elseif (file_exists(dirname(__FILE__) . '/alibabacloud-oss-php-sdk-v2-0.4.0/vendor/autoload.php')) {
        // Fallback to source directory
        require_once dirname(__FILE__) . '/alibabacloud-oss-php-sdk-v2-0.4.0/vendor/autoload.php';
    }
}

// 确保加载了 SDK (如果 PHAR 已加载，这步通常不需要，但为了兼容性保留检查)
if (!class_exists('AlibabaCloud\Oss\V2\Client')) {
    if (file_exists(dirname(__FILE__) . '/alibabacloud-oss-php-sdk-v2-0.4.0.phar')) {
         require_once dirname(__FILE__) . '/alibabacloud-oss-php-sdk-v2-0.4.0.phar';
    } elseif (file_exists(dirname(__FILE__) . '/alibabacloud-oss-php-sdk-v2-0.4.0/autoload.php')) {
        require_once dirname(__FILE__) . '/alibabacloud-oss-php-sdk-v2-0.4.0/autoload.php';
    }
}

use AlibabaCloud\Oss\V2 as OssV2;
use AlibabaCloud\Oss\V2\Models;
use AlibabaCloud\Oss\V2\Credentials;
use AlibabaCloud\Oss\V2\Exception\ServiceException;
use GuzzleHttp\Psr7\Utils;

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Adapter for Alibaba Cloud OSS SDK v2 (PHP 8+) to replace the old OU_ALIOSS class.
 * Maintains backward compatibility for aliyun-oss-upload.
 */
class OU_ALIOSS {
    private $ossClient;
    private $access_id;
    private $access_key;
    private $endpoint;
    private $region;
    private $security_token;

    public function __construct($access_id = NULL, $access_key = NULL, $hostname = NULL, $security_token = NULL) {
        if ($access_id && $access_key) {
            $this->setAuth($access_id, $access_key, $hostname, $security_token);
        } elseif (defined('OSS_ACCESS_ID') && defined('OSS_ACCESS_KEY')) {
            $this->setAuth(OSS_ACCESS_ID, OSS_ACCESS_KEY, defined('OSS_ENDPOINT') ? OSS_ENDPOINT : NULL);
        }
    }

    public function setAuth($access_id, $access_key, $hostname = NULL, $security_token = NULL) {
        $this->access_id = $access_id;
        $this->access_key = $access_key;
        $this->endpoint = $hostname ? $hostname : (defined('OSS_ENDPOINT') ? OSS_ENDPOINT : 'oss-cn-hangzhou.aliyuncs.com');
        $this->security_token = $security_token;
        
        // 解析 Region
        $this->region = $this->parseRegion($this->endpoint);
        
        $this->ossClient = null; // Reset client to force re-init
    }

    private function parseRegion($endpoint) {
        // 简单尝试从 endpoint 解析 region
        // 例如 oss-cn-hangzhou.aliyuncs.com -> cn-hangzhou
        if (preg_match('/oss-([a-z0-9-]+)(\-internal)?\.aliyuncs\.com/', $endpoint, $matches)) {
            return $matches[1];
        }
        return 'cn-hangzhou'; // 默认
    }

    private function getClient() {
        if (!$this->ossClient) {
            if (!$this->access_id || !$this->access_key) {
                if (defined('OSS_ACCESS_ID') && defined('OSS_ACCESS_KEY')) {
                     $this->setAuth(OSS_ACCESS_ID, OSS_ACCESS_KEY, defined('OSS_ENDPOINT') ? OSS_ENDPOINT : NULL);
                } else {
                    throw new Exception("OSS credentials not set");
                }
            }
            try {
                $cfg = new OssV2\Config(
                    $this->region,
                    $this->endpoint,
                    null,
                    new Credentials\StaticCredentialsProvider($this->access_id, $this->access_key, $this->security_token)
                );
                $this->ossClient = new OssV2\Client($cfg);
            } catch (Exception $e) {
                throw $e;
            }
        }
        return $this->ossClient;
    }

    public function create_mpu_object($bucket, $object, $options = null) {
        try {
            $client = $this->getClient();
            $file = isset($options['fileUpload']) ? $options['fileUpload'] : null;
            // 兼容旧常量
            if (!$file && isset($options['fileUpload'])) { 
                 $file = $options['fileUpload'];
            }

            if (!$file) {
                 if (isset($options['content'])) {
                     return $this->upload_file_by_content($bucket, $object, $options);
                 }
                 throw new Exception("No file provided for upload");
            }

            // 准备 PutObjectRequest
            // Uploader 会处理大文件分片
            $request = new Models\PutObjectRequest($bucket, $object);
            
            // 处理选项
            if (isset($options['headers'])) {
                if (isset($options['headers']['x-oss-object-acl'])) {
                    $request->acl = $options['headers']['x-oss-object-acl'];
                }
                if (isset($options['headers']['Content-Type'])) {
                    $request->contentType = $options['headers']['Content-Type'];
                }
                if (isset($options['headers']['Cache-Control'])) {
                    $request->cacheControl = $options['headers']['Cache-Control'];
                }
                if (isset($options['headers']['Content-Disposition'])) {
                    $request->contentDisposition = $options['headers']['Content-Disposition'];
                }
            }

            // 使用 Uploader 进行文件上传
            // Uploader 会自动选择简单上传或分片上传
            $uploader = new OssV2\Uploader($client);
            $result = $uploader->uploadFile($request, $file);
            
            return new OU_Response(200, "Upload successful");
        } catch (ServiceException $e) {
            return new OU_Response($e->getStatusCode(), $e->getErrorMessage());
        } catch (Exception $e) {
            return new OU_Response(400, $e->getMessage());
        }
    }

    public function create_mtu_object_by_dir($bucket, $dir, $recursive = false, $exclude = ".|..|.svn|.git", $options = null) {
        if (!is_dir($dir)) throw new Exception(sprintf(__('%s is not a directory.', 'aliyun-oss-upload'), esc_html($dir)));

        $files = $this->read_dir($dir, $exclude, $recursive);
        if (empty($files)) throw new Exception(sprintf(__('%s is empty.', 'aliyun-oss-upload'), esc_html($dir)));

        $index = 1;
        $client = $this->getClient();
        $uploader = new OssV2\Uploader($client);

        foreach ($files as $item) {
             echo esc_html($index++) . ". " . esc_html($item['path']) . " - ";
             if (is_dir($item['path'])) {
                 echo esc_html("Ignored directory.") . "<br/>\n";
                 flush();
                 continue;
             }

             $objectKey = $item['file'];
             
             try {
                 $request = new Models\PutObjectRequest($bucket, $objectKey);
                 $uploader->uploadFile($request, $item['path']);
                 echo "<font color=green>" . esc_html("Upload Success.") . "</font><br/>\n";
             } catch (ServiceException $e) {
                 echo "<font color=red>" . esc_html("Upload Failed: " . $e->getErrorMessage()) . "</font><br/>\n";
             } catch (Exception $e) {
                 echo "<font color=red>" . esc_html("Upload Failed: " . $e->getMessage()) . "</font><br/>\n";
             }
             flush();
        }
        return true;
    }

    // Helper to read dir (保持原样)
    private function read_dir($dir, $exclude = ".|..|.svn|.git", $recursive = false) {
        $file_list_array = array();
        $base_path = $dir;
        $exclude_array = explode("|", $exclude);
        $exclude_array = array_unique(array_merge($exclude_array,array('.','..')));

        if($recursive){
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $new_file)
            {
                if ($new_file->isDir()) continue;
                $object = str_replace($base_path, '', $new_file);
                if(!in_array(strtolower($object), $exclude_array)){
                    $object = ltrim($object, '/');
                    if (is_file($new_file)){
                        if(stripos($object, '.DS_Store') !== false) continue;
                        $key = md5($new_file.$object, false);
                        $file_list_array[$key] = array('path' => $new_file,'file' => $object,);
                    }
                }
            }
        }
        else if($handle = @opendir($dir)){
            while ( false !== ($file = @readdir($handle))){
                if(!in_array(strtolower($file), $exclude_array)){
                    $new_file = $dir.'/'.$file;
                    $object = $file;
                    $object = ltrim($object, '/');
                    if (is_file($new_file)){
                        $key = md5($new_file.$object, false);
                        $file_list_array[$key] = array('path' => $new_file,'file' => $object,);
                    }
                }
            }
            closedir($handle);
        }
        return $file_list_array;
    }

    public function delete_object($bucket, $object, $options = NULL) {
         try {
             $request = new Models\DeleteObjectRequest($bucket, $object);
             $this->getClient()->deleteObject($request);
             return new OU_Response(204, "");
         } catch (ServiceException $e) {
             return new OU_Response($e->getStatusCode(), $e->getErrorMessage());
         } catch (Exception $e) {
             return new OU_Response(400, $e->getMessage());
         }
    }

    public function delete_objects($bucket, $objects, $options = null) {
        try {
            // V2 DeleteMultipleObjectsRequest 
            $delObjects = [];
            foreach ($objects as $obj) {
                $delObjects[] = new Models\DeleteObject($obj);
            }
            // Pass objects and quiet=false directly
            $request = new Models\DeleteMultipleObjectsRequest($bucket, $delObjects, null, false);
            
            $this->getClient()->deleteMultipleObjects($request);
            return new OU_Response(204, "");
        } catch (ServiceException $e) {
            return new OU_Response($e->getStatusCode(), $e->getErrorMessage());
        } catch (Exception $e) {
            return new OU_Response(400, $e->getMessage());
        }
    }

    public function list_object($bucket, $options = NULL) {
        try {
             $request = new Models\ListObjectsRequest($bucket);
             if (is_array($options)) {
                 if (isset($options['prefix'])) $request->prefix = $options['prefix'];
                 if (isset($options['delimiter'])) $request->delimiter = $options['delimiter'];
                 if (isset($options['marker'])) $request->marker = $options['marker'];
                 if (isset($options['max-keys'])) $request->maxKeys = intval($options['max-keys']);
             }

             $result = $this->getClient()->listObjects($request);

             // Convert objects to simple arrays for JSON encoding
             $objectList = array();
             if ($result->contents) {
                 foreach ($result->contents as $info) {
                     $objectList[] = array(
                         'Key' => $info->key,
                         'Size' => $info->size,
                         'LastModified' => $info->lastModified ? $info->lastModified->format(DateTime::ATOM) : '',
                         'ETag' => $info->etag,
                         'Type' => $info->type,
                         'StorageClass' => $info->storageClass
                     );
                 }
             }

             $prefixList = array();
             if ($result->commonPrefixes) {
                 foreach ($result->commonPrefixes as $info) {
                     $prefixList[] = array(
                         'Prefix' => $info->prefix
                     );
                 }
             }

             $data = array(
                'is_legacy_adapter' => true,
                'object_list' => $objectList,
                'prefix_list' => $prefixList,
                'isTruncated' => $result->isTruncated,
                'nextMarker' => $result->nextMarker,
                'prefix' => $result->prefix,
             );

             return new OU_Response(200, json_encode($data));
        } catch (ServiceException $e) {
             return new OU_Response($e->getStatusCode(), $e->getErrorMessage());
        } catch (Exception $e) {
             return new OU_Response(400, $e->getMessage());
        }
    }

    public function get_object_meta($bucket, $object, $options = NULL) {
         try {
             $request = new Models\HeadObjectRequest($bucket, $object);
             $res = $this->getClient()->headObject($request);
             
             // 映射结果到数组
             // HeadObjectResult 包含很多属性，但 OSSWrapper 期望一个数组 (headers)
             // 我们需要把结果转回 array
             $headers = array();
             $headers['content-length'] = $res->contentLength;
             $headers['last-modified'] = $res->lastModified ? $res->lastModified->format(DateTime::ATOM) : '';
             $headers['content-type'] = $res->contentType;
             $headers['etag'] = $res->etag;
             
             // Add _info for compatibility
             if (isset($headers['last-modified'])) {
                 $headers['_info']['filetime'] = strtotime($headers['last-modified']);
             } else {
                 $headers['_info']['filetime'] = time();
             }
             if (isset($headers['content-length'])) {
                 $headers['_info']['download_content_length'] = $headers['content-length'];
             }

             return new OU_Response(200, "", $headers);
         } catch (ServiceException $e) {
             return new OU_Response($e->getStatusCode(), $e->getErrorMessage());
         } catch (Exception $e) {
             return new OU_Response(404, $e->getMessage());
         }
    }

    public function get_object($bucket, $object, $options = NULL) {
        try {
             $request = new Models\GetObjectRequest($bucket, $object);
             $res = $this->getClient()->getObject($request);
             // $res->body 是 StreamInterface
             $content = (string)$res->body;
             return new OU_Response(200, $content);
        } catch (ServiceException $e) {
             return new OU_Response($e->getStatusCode(), $e->getErrorMessage());
        } catch (Exception $e) {
             return new OU_Response(404, $e->getMessage());
        }
    }

    public function upload_file_by_content($bucket, $object, $options = NULL) {
         try {
             $content = isset($options['content']) ? $options['content'] : '';
             $request = new Models\PutObjectRequest($bucket, $object);
             // 直接调用 putObject，body 为 content
             $request->body = GuzzleHttp\Psr7\Utils::streamFor($content);
             
             $this->getClient()->putObject($request);
             return new OU_Response(200, "");
         } catch (ServiceException $e) {
             return new OU_Response($e->getStatusCode(), $e->getErrorMessage());
         } catch (Exception $e) {
             return new OU_Response(400, $e->getMessage());
         }
    }

    public function copy_object($from_bucket, $from_object, $to_bucket, $to_object, $options = NULL) {
        try {
            $request = new Models\CopyObjectRequest($to_bucket, $to_object, $from_object);
            // 注意：CopyObjectRequest 第三个参数是 sourceKey，如果跨 bucket 需要 /bucket/key 格式
            // 假设这里 from_bucket 是同一个或者已经包含在 source 里？
            // V2 SDK CopyObjectRequest:
            // public ?string $bucket; // Dest bucket
            // public ?string $key; // Dest key
            // public ?string $sourceKey; // Source path (e.g. /bucket/key)
            
            $sourceKey = "/$from_bucket/$from_object";
            $request->sourceKey = $sourceKey;
            
            $this->getClient()->copyObject($request);
            return new OU_Response(200, "");
        } catch (ServiceException $e) {
            return new OU_Response($e->getStatusCode(), $e->getErrorMessage());
        } catch (Exception $e) {
            return new OU_Response(400, $e->getMessage());
        }
    }

    public function create_object_dir($bucket, $object, $options = NULL) {
         try {
             // 确保以 / 结尾
             if (substr($object, -1) !== '/') {
                 $object .= '/';
             }
             $request = new Models\PutObjectRequest($bucket, $object);
             $request->body = GuzzleHttp\Psr7\Utils::streamFor('');
             $this->getClient()->putObject($request);
             return new OU_Response(200, "");
         } catch (ServiceException $e) {
             return new OU_Response($e->getStatusCode(), $e->getErrorMessage());
         } catch (Exception $e) {
             return new OU_Response(400, $e->getMessage());
         }
    }
}

class OU_Response {
    public $status;
    public $body;
    public $header;

    public function __construct($status, $body, $header = array()) {
        $this->status = $status;
        $this->body = $body;
        $this->header = $header;
    }

    public function isOK() {
        return ((int)($this->status / 100)) == 2;
    }
}
