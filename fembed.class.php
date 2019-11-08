<?php

class FembedUpload
{
    private $file;
    private $account;
    private $http;
    private $fingerprint = null;
    private $retry = 0;
    private $max_retry = 5;
    private $last_message = '';
    private $cache_dir = __DIR__ .'/fembed_cache';

    public function __construct()
    {
        $this->http = new GuzzleHttp\Client([
            'base_uri' => 'https://www.fembed.com/api/',
            'timeout' => 10,
        ]);
        if (!is_dir($this->cache_dir)) {
            mkdir($this->cache_dir, 0755, true);
        }
    }

    public function SetInput($file)
    {
        $this->file = $file;
    }

    public function SetAccount($account)
    {
        $this->account = $account;
    }

    public function ClearAll()
    {
        $this->account = null;
        $this->file = null;
        $this->fingerprint = null;
        $this->retry = 0;
        $this->last_message = '';
    }

    public function Run()
    {
        if (!is_file($this->file)) {
            return $this->errorResponse('Source File does not exist!');
        }
        if (!isset($this->account->client_id) || !isset($this->account->client_secret)) {
            return $this->errorResponse('missing client_id and/or client_secret');
        }

        // Make sure chunk will not pass PHP Memory Limit
        $chunkSizeBytes = 64 * 1024 * 1024;
        $total = $this->byteConvert(ini_get('memory_limit'));
        $used_now = memory_get_usage(true);
        $free = $total - $used_now - 2147483648; // Keep 2GB for overhead
        /*if ($free < $chunkSizeBytes) {
            return $this->errorResponse('We do not have enough Memory to upload video');
        }*/

        $filesize = filesize($this->file);
        $cache_key = md5($this->file.$filesize);
        $fingerprintUrl = $this->getCache($cache_key);
        if (!$fingerprintUrl) {
            $res = $this->getEndpointToken();
            if (!$res->success) {
                if (strpos($res->data, '403') !== false) {
                    return $this->errorResponse('Account is UnAccessable');
                }

                return $this->errorResponse($res->data);
            }

            $endpoint = $res->data->url;
            $token = $res->data->token;

            echo 'Upload url: '.$endpoint;
            $metadata = ['name' => basename($this->file), 'token' => $token];
            $metadata = $this->base64Header($metadata);

            $res = $this->getFingerprintUrl($endpoint, $metadata, $filesize);
            if (!$res->success) {
                return $this->errorResponse($res->data);
            }

            $fingerprintUrl = $res->data;
            $this->setCache($cache_key, $fingerprintUrl);
        }

        $r = explode('/', trim($fingerprintUrl, '/'));
        $this->fingerprint = array_pop($r);
        echo 'Fingerprint: '.$this->fingerprint.PHP_EOL;

        echo 'Uploading';
        if (!$this->uploadVideo($fingerprintUrl, $chunkSizeBytes, $filesize)) {
            $this->removeFingerprintUrl($fingerprintUrl);

            return $this->errorResponse($this->last_message);
        }

        echo 'Upload successfully'.PHP_EOL;
        sleep(20); // wait for video ready
        $res = $this->getVideoId();
        if (!$res->success) {
            echo 'But get video id failed'.PHP_EOL;

            return $this->errorResponse($res->data);
        }
        $this->clearCache($cache_key);

        echo 'Great, Fembed ID: '.$res->data.PHP_EOL;

        return $this->successResponse($res->data);
    }

    private function getCache($key)
    {
        $key = $this->cache_dir.'/'.$key;
        if (!is_file($key)) {
            return null;
        }
        if (time() - filemtime($key) > 24 * 60 * 60) {
            unlink($key);

            return null;
        }

        return @file_get_contents($key);
    }

    private function setCache($key, $data)
    {
        $key = $this->cache_dir.'/'.$key;

        return @file_put_contents($key, $data);
    }

    private function clearCache($key)
    {
        $key = $this->cache_dir.'/'.$key;
        if (!is_file($key)) {
            return null;
        }

        return @unlink($key);
    }

    private function base64Header($array)
    {
        $str = '';
        foreach ($array as $key => $value) {
            $str .= $key.' '.base64_encode($value).',';
        }

        return trim($str, ',');
    }

    private function getEndpointToken()
    {
        try {
            $res = $this->http->post('upload', [
                'form_params' => [
                    'client_id' => $this->account->client_id,
                    'client_secret' => $this->account->client_secret,
                ],
            ]);
            $this->retry = 0;
            $res = json_decode($res->getBody());
        } catch (Exception $e) {
            $res = (object) ['success' => false, 'data' => $e->getMessage()];
        }

        if (!$res->success) {
            if (strpos($res->data, '403') !== false) {
                return $res;
            }
            if ($this->retry <= $this->max_retry) {
                ++$this->retry;
                sleep($this->retry);

                return $this->getEndpointToken();
            }

            return $res;
        }
        $this->retry = 0;

        return $res;
    }

    private function getFingerprintUrl($endpoint, $metadata, $filesize)
    {
        try {
            $res = $this->http->post($endpoint, [
                'headers' => [
                    'Tus-Resumable' => '1.0.0',
                    'Upload-Metadata' => $metadata,
                    'Upload-Length' => $filesize,
                    'Content-Length' => 0,
                ],
            ]);
            $this->retry = 0;
            $code = $res->getStatusCode();
            $fingerprintUrl = current($res->getHeader('Location'));
        } catch (Exception $e) {
            $code = 500;
            $fingerprintUrl = $e->getMessage();
        }

        $res = (object) ['success' => $code == 201, 'data' => $fingerprintUrl];

        if (!$res->success) {
            if ($this->retry <= $this->max_retry) {
                ++$this->retry;
                sleep($this->retry);

                return $this->getFingerprintUrl($endpoint, $metadata, $filesize);
            }

            return $res;
        }
        $this->retry = 0;

        return $res;
    }

    private function uploadVideo($fingerprintUrl, $chunkSizeBytes, $filesize = null)
    {
        if (!$filesize) {
            $filesize = filesize($this->file);
        }
        $handle = fopen($this->file, 'rb');
        $offset = $this->getOffset($fingerprintUrl);

        while (true) {
            if ($offset) {
                fseek($handle, $offset);
            }
            $chunk = fread($handle, $chunkSizeBytes);
            if (!$chunk) {
                break;
            }
            $newoffset = $this->nextChunk($fingerprintUrl, $chunk, $offset, $filesize);
            if (!$newoffset || $offset == $newoffset) {
                break;
            }
            echo ' => '.$newoffset;
            $offset = $newoffset;
        }
        fclose($handle);
        echo PHP_EOL;

        if ($offset == $filesize) {
            return true;
        }

        return false;
    }

    private function nextChunk($fingerprintUrl, $chunk, $offset, $filesize)
    {
        try {
            $length = strlen($chunk);
            $res = $this->http->patch($fingerprintUrl, [
                'body' => $chunk,
                'headers' => [
                    'Tus-Resumable' => '1.0.0',
                    'Content-Type' => 'application/offset+octet-stream',
                    'Content-Length' => $length,
                    'Upload-Offset' => $offset,
                ],
                'timeout' => 60,
            ]);
            $this->retry = 0;

            return (int) current($res->getHeader('Upload-Offset'));
        } catch (Exception $e) {
            $this->last_message = $e->getMessage();
            $newoffset = $this->getOffset($fingerprintUrl);
            if ($newoffset == $offset) {
                if ($this->retry <= $this->max_retry) {
                    ++$this->retry;
                    sleep($this->retry);

                    return $this->nextChunk($fingerprintUrl, $chunk, $offset, $filesize);
                }
            } else {
                $this->retry = 0;
            }

            return $newoffset;
        }
    }

    private function getOffset($fingerprintUrl)
    {
        try {
            $res = $this->http->head($fingerprintUrl, [
                'headers' => [
                    'Tus-Resumable' => '1.0.0',
                ],
                'timeout' => 5,
            ]);
            $this->retry = 0;

            return (int) current($res->getHeader('Upload-Offset'));
        } catch (Exception $e) {
            if ($this->retry <= $this->max_retry) {
                ++$this->retry;
                sleep($this->retry);

                return $this->getOffset($fingerprintUrl);
            }

            return 0;
        }
    }

    private function removeFingerprintUrl($fingerprintUrl)
    {
        try {
            $this->http->delete($fingerprintUrl, [
                'headers' => [
                    'Tus-Resumable' => '1.0.0',
                ],
            ]);
            $this->retry = 0;

            return true;
        } catch (Exception $e) {
            if ($this->retry <= $this->max_retry) {
                ++$this->retry;
                sleep($this->retry);

                return $this->removeFingerprintUrl($fingerprintUrl);
            }

            return false;
        }
    }

    private function getVideoId($sleep = 10)
    {
        try {
            $res = $this->http->post('fingerprint', [
                'form_params' => [
                    'client_id' => $this->account->client_id,
                    'client_secret' => $this->account->client_secret,
                    'file_fingerprint' => $this->fingerprint,
                ],
            ]);
            $this->retry = 0;
            $res = json_decode($res->getBody());
        } catch (Exception $e) {
            $res = (object) ['success' => false, 'data' => $e->getMessage()];
        }

        if (!$res->success) {
            if ($this->retry <= $this->max_retry) {
                ++$this->retry;
                sleep($this->retry + $sleep);

                return $this->getVideoId($sleep);
            }

            return $res;
        }
        $this->retry = 0;

        return $res;
    }

    private function errorResponse($data)
    {
        $res = new stdClass();
        $res->result = 'error';
        $res->data = $data;

        return $res;
    }

    private function successResponse($data = null)
    {
        $res = new stdClass();
        $res->result = 'success';
        $res->data = $data;

        return $res;
    }

    private function byteConvert($val)
    {
        if (empty($val)) {
            return 0;
        }

        $val = trim($val);

        preg_match('#([0-9]+)[\s]*([a-z]+)#i', $val, $matches);

        $last = '';
        if (isset($matches[2])) {
            $last = $matches[2];
        }

        if (isset($matches[1])) {
            $val = (int) $matches[1];
        }

        switch (strtolower($last)) {
            case 'g':
            case 'gb':
                $val *= 1024;
            case 'm':
            case 'mb':
                $val *= 1024;
            case 'k':
            case 'kb':
                $val *= 1024;
        }

        return (int) $val;
    }
}
