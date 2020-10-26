<?php

namespace CP6\BunnyCDN;

/**
 * Bunny CDN storage zone API class
 * @version  1.2
 * @author corbpie
 */
class BunnyAPI
{
    const API_KEY = 'XXXX-XXXX-XXXX';//BunnyCDN API key
    const API_URL = 'https://bunnycdn.com/api/';//URL for BunnyCDN API
    const STORAGE_API_URL = 'https://storage.bunnycdn.com/';//URL for storage based API
    const HOSTNAME = 'storage.bunnycdn.com';//FTP hostname
    private string $api_key;
    private string $access_key;
    private string $storage_name;
    private $connection;
    private string $data;

    /**
     * Option to display notices and errors for debugging and execution time amount
     * @param bool $show_errors
     * @param int $execution_time
     */
    public function __construct($show_errors = false, $execution_time = 240)
    {
        if ($this->constApiKeySet()) {
            $this->api_key = BunnyAPI::API_KEY;
        }
        ini_set('max_execution_time', $execution_time);
        if ($show_errors) {
            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);
            error_reporting(E_ALL);
        }
    }

    /**
     * Sets access key and the storage name, makes FTP connection with this
     * @param string $api_key (storage zone password)
     * @return string
     * @throws Exception
     */
    public function apiKey(string $api_key = '')
    {
        if (!isset($api_key) or trim($api_key) == '') {
            throw new Exception("You must provide an API key");
        }
        $this->api_key = $api_key;
        return json_encode(array('response' => 'success', 'action' => 'apiKey'));
    }

    /**
     * Sets and creates auth + FTP connection to a storage zone
     * @param string $storage_name
     * @param string $access_key
     * @return string
     * @throws Exception
     */
    public function zoneConnect(string $storage_name, string $access_key)
    {
        $this->storage_name = $storage_name;
        $this->access_key = $access_key;
        $conn_id = ftp_connect((BunnyAPI::HOSTNAME));
        $login = ftp_login($conn_id, $storage_name, $access_key);
        ftp_pasv($conn_id, true);
        if ($conn_id) {
            $this->connection = $conn_id;
            return json_encode(array('response' => 'success', 'action' => 'zoneConnect'));
        } else {
            throw new Exception("Could not make FTP connection to " . (BunnyAPI::HOSTNAME) . "");
        }
    }

    /**
     * Sets the MySQL connection (Optional! Only if using MySQL functions)
     * @return object
     */
    public function db_connect()
    {
        $db_user = 'root';
        $db_password = '';
        $db = "mysql:host=127.0.0.1;dbname=bunnycdn;charset=utf8mb4";
        $options = array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC);
        return new PDO($db, $db_user, $db_password, $options);
    }

    /**
     * Checks if API key has been hard coded with the constant API_KEY
     * @return bool
     */
    protected function constApiKeySet()
    {
        if (!defined("BunnyAPI::API_KEY") || empty(BunnyAPI::API_KEY)) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * cURL execution with headers and parameters
     * @param string $method
     * @param string $url
     * @param string|boolean $params
     * @return string
     * @throws Exception
     */
    private function APIcall(string $method, string $url, $params = false)
    {

        if (is_null($this->api_key) && !$this->constApiKeySet()) {
            throw new Exception("apiKey() is not set");
        }
        $curl = curl_init();
        switch ($method) {
            case "POST":
                curl_setopt($curl, CURLOPT_POST, 1);
                if ($params)
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
                break;
            case "PUT":
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
                if ($params)
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
                break;
            case "DELETE":
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
                if ($params)
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
                break;
            default:
                if ($params)
                    $url = sprintf("%s?%s", $url, http_build_query($params));
        }
        curl_setopt($curl, CURLOPT_URL, "" . (BunnyAPI::API_URL) . "$url");
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "AccessKey: {$this->api_key}"));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        $result = curl_exec($curl);
        curl_close($curl);
        $this->data = $result;
        return $result;
    }

    /**
     * Returns all pull zones and information
     * @return string
     */
    public function listPullZones()
    {
        return $this->APIcall('GET', 'pullzone');
    }

    /**
     * Creates pull zone
     * @param string $name
     * @param string $origin
     * @param array $args
     * @return string
     */
    public function createPullZone(string $name, string $origin, array $args = array())
    {
        $args = array_merge(
            array(
                'Name' => $name,
                'OriginUrl' => $origin,
            ),
            $args
        );
        return $this->APIcall('POST', 'pullzone', json_encode($args));
    }

    /**
     * Returns pull zone information for id
     * @param int $id
     * @return string
     * @throws Exception
     */
    public function pullZoneData(int $id)
    {
        return $this->APIcall('GET', "pullzone/$id");
    }

    /**
     * Purge the pull zone with id
     * @param int $id
     * @param bool $db_log
     * @return string
     */
    public function purgePullZone(int $id, bool $db_log = false)
    {
        if ($db_log) {
            $this->actionsLog('PURGE PZ', $id);
        }
        return $this->APIcall('POST', "pullzone/$id/purgeCache");
    }

    /**
     * Delete pull zone for id
     * @param int $id
     * @param bool $db_log
     * @return string
     */
    public function deletePullZone(int $id, bool $db_log = false)
    {
        if ($db_log) {
            $this->actionsLog('DELETE PZ', $id);
        }
        return $this->APIcall('DELETE', "pullzone/$id");
    }

    /**
     * Returns pull zone hostname count and list
     * @param int $id
     * @return array
     */
    public function pullZoneHostnames(int $id)
    {
        $data = json_decode($this->pullZoneData($id), true);
        if (isset($data['Hostnames'])) {
            $hn_count = count($data['Hostnames']);
            $hn_arr = array();
            foreach ($data['Hostnames'] as $a_hn) {
                $hn_arr[] = array(
                    'id' => $a_hn['Id'],
                    'hostname' => $a_hn['Value'],
                    'force_ssl' => $a_hn['ForceSSL']
                );
            }
            return array(
                'hostname_count' => $hn_count,
                'hostnames' => $hn_arr
            );
        } else {
            return array('hostname_count' => 0);
        }
    }

    /**
     * Add hostname to pull zone for id
     * @param int $id
     * @param string $hostname
     * @param bool $db_log
     * @return string
     */
    public function addHostnamePullZone(int $id, string $hostname, bool $db_log = false)
    {
        if ($db_log) {
            $this->actionsLog('ADD HN', $id, $hostname);
        }
        return $this->APIcall('POST', 'pullzone/addHostname', json_encode(array("PullZoneId" => $id, "Hostname" => $hostname)));
    }

    /**
     * Remove hostname for pull zone
     * @param int $id
     * @param string $hostname
     * @param bool $db_log
     * @return string
     */
    public function removeHostnamePullZone(int $id, string $hostname, bool $db_log = false)
    {
        if ($db_log) {
            $this->actionsLog('REMOVE HN', $id, $hostname);
        }
        return $this->APIcall('DELETE', 'pullzone/deleteHostname', json_encode(array("id" => $id, "hostname" => $hostname)));
    }

    /**
     * Load a free certificate provided by Let’s Encrypt.
     * @param string $hostname
     * @return string
     */
    public function addFreeSSLCertificate(string $hostname)
    {
        return $this->APIcall('GET', 'pullzone/loadFreeCertificate?hostname=' . $hostname);
    }

    /**
     * Set Force SSL status for pull zone
     * @param int $id
     * @param string $hostname
     * @param boolean $force_ssl
     * @return string
     */
    public function forceSSLPullZone(int $id, string $hostname, bool $force_ssl = true)
    {
        return $this->APIcall('POST', 'pullzone/setForceSSL', json_encode(array("PullZoneId" => $id, "Hostname" => $hostname, 'ForceSSL' => $force_ssl)));
    }

    /**
     * Returns Blocked ip data for pull zone for id
     * @param int $id
     * @return array
     */
    public function listBlockedIpPullZone(int $id)
    {
        $data = json_decode($this->pullZoneData($id), true);
        if (isset($data['BlockedIps'])) {
            $ip_count = count($data['BlockedIps']);
            $ip_arr = array();
            foreach ($data['BlockedIps'] as $a_hn) {
                $ip_arr[] = $a_hn;
            }
            return array(
                'blocked_ip_count' => $ip_count,
                'ips' => $ip_arr
            );
        } else {
            return array('blocked_ip_count' => 0);
        }
    }

    /**
     * Block an ip for pull zone for id
     * @param int $id
     * @param string $ip
     * @param bool $db_log
     * @return string
     */
    public function addBlockedIpPullZone(int $id, string $ip, bool $db_log = false)
    {
        if ($db_log) {
            $this->actionsLog('ADD BLOCKED IP', $id, $ip);
        }
        return $this->APIcall('POST', 'pullzone/addBlockedIp', json_encode(array("PullZoneId" => $id, "BlockedIp" => $ip)));
    }

    /**
     * Remove a blocked ip for pull zone id
     * @param int $id
     * @param string $ip
     * @param bool $db_log
     * @return string
     */
    public function unBlockedIpPullZone(int $id, string $ip, bool $db_log = false)
    {
        if ($db_log) {
            $this->actionsLog('UN BLOCKED IP', $id, $ip);
        }
        return $this->APIcall('POST', 'pullzone/removeBlockedIp', json_encode(array("PullZoneId" => $id, "BlockedIp" => $ip)));
    }

    /**
     * Returns log data array for pull zone id
     * @param int $id
     * @param string $date Must be within past 3 days (mm-dd-yy)
     * @return array
     */
    public function pullZoneLogs(int $id, string $date)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "https://logging.bunnycdn.com/$date/$id.log");
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "AccessKey: {$this->api_key}"));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        $result = curl_exec($curl);
        curl_close($curl);
        $linetoline = explode("\n", $result);
        $line = array();
        foreach ($linetoline as $v1) {
            if (isset($v1) && strlen($v1) > 0) {
                $log_format = explode('|', $v1);
                $details = array(
                    'cache_result' => $log_format[0],
                    'status' => intval($log_format[1]),
                    'datetime' => date('Y-m-d H:i:s', round($log_format[2] / 1000, 0)),
                    'bytes' => intval($log_format[3]),
                    'ip' => $log_format[5],
                    'referer' => $log_format[6],
                    'file_url' => $log_format[7],
                    'user_agent' => $log_format[9],
                    'request_id' => $log_format[10],
                    'cdn_dc' => $log_format[8],
                    'zone_id' => intval($log_format[4]),
                    'country_code' => $log_format[11]
                );
                array_push($line, $details);
            }
        }
        return $line;
    }

    /**
     * Returns all storage zones and information
     * @return string
     */
    public function listStorageZones()
    {
        return $this->APIcall('GET', 'storagezone');
    }

    /**
     * Create storage zone
     * @param string $name
     * @param bool $db_log
     * @return string
     */
    public function addStorageZone(string $name, bool $db_log = false)
    {
        if ($db_log) {
            $this->actionsLog('ADD SZ', $name);
        }
        return $this->APIcall('POST', 'storagezone', json_encode(array("Name" => $name)));
    }

    /**
     * Delete storage zone
     * @param int $id
     * @param bool $db_log
     * @return string
     */
    public function deleteStorageZone(int $id, bool $db_log = false)
    {
        if ($db_log) {
            $this->actionsLog('DELETE SZ', $id);
        }
        return $this->APIcall('DELETE', "storagezone/$id");
    }

    /**
     * Purge cache for a URL
     * @param $url
     * @return string
     */
    public function purgeCache(string $url)
    {
        return $this->APIcall('POST', 'purge', json_encode(array("url" => $url)));
    }

    /**
     * Convert and format bytes
     * @param int $bytes
     * @param string $convert_to
     * @param bool $format
     * @param int $decimals
     * @return float
     */
    public function convertBytes(int $bytes, string $convert_to = 'GB', bool $format = true, int $decimals = 2)
    {
        if ($convert_to == 'GB') {
            $value = ($bytes / 1073741824);
        } elseif ($convert_to == 'MB') {
            $value = ($bytes / 1048576);
        } elseif ($convert_to == 'KB') {
            $value = ($bytes / 1024);
        } else {
            $value = $bytes;
        }
        if ($format) {
            return number_format($value, $decimals);
        } else {
            return $value;
        }
    }

    /**
     * Get statistics
     * @return string
     */
    public function getStatistics()
    {
        return $this->APIcall('GET', 'statistics');
    }

    /**
     * Get billing information
     * @return string
     */
    public function getBilling()
    {
        return $this->APIcall('GET', 'billing');
    }

    /**
     * Get current account balance
     * @return string
     */
    public function balance()
    {
        return json_decode($this->getBilling(), true)['Balance'];
    }

    /**
     * Gets current month charge amount
     * @return string
     */
    public function monthCharges()
    {
        return json_decode($this->getBilling(), true)['ThisMonthCharges'];
    }

    /**
     * Gets total charge amount and first charge date time
     * @param bool $format
     * @param int $decimals
     * @return array
     */
    public function totalBillingAmount(bool $format = false, int $decimals = 2)
    {
        $data = json_decode($this->getBilling(), true);
        $tally = 0;
        foreach ($data['BillingRecords'] as $charge) {
            $tally = ($tally + $charge['Amount']);
        }
        if ($format) {
            return array('amount' => floatval(number_format($tally, $decimals)), 'since' => str_replace('T', ' ', $charge['Timestamp']));
        } else {
            return array('amount' => $tally, 'since' => str_replace('T', ' ', $charge['Timestamp']));
        }
    }

    /**
     * Array for month charges per zone
     * @return array
     */
    public function monthChargeBreakdown()
    {
        $ar = json_decode($this->getBilling(), true);
        return array('storage' => $ar['MonthlyChargesStorage'], 'EU' => $ar['MonthlyChargesEUTraffic'],
            'US' => $ar['MonthlyChargesUSTraffic'], 'ASIA' => $ar['MonthlyChargesASIATraffic'],
            'SA' => $ar['MonthlyChargesSATraffic']);
    }

    /**
     * Apply a coupon code
     * @param string $code
     * @return string
     */
    public function applyCoupon(string $code)
    {
        return $this->APIcall('POST', 'applycode', json_encode(array("couponCode" => $code)));
    }

    /**
     * Create a folder
     * @param string $name folder name to create
     * @param bool $db_log
     * @return string
     * @throws Exception
     */
    public function createFolder(string $name, bool $db_log = false)
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        if (ftp_mkdir($this->connection, $name)) {
            if ($db_log) {
                $this->actionsLog('CREATE FOLDER', $name);
            }
            return json_encode(array('response' => 'success', 'action' => 'createFolder'));
        } else {
            throw new Exception("Could not create folder $name");
        }
    }

    /**
     * Delete a folder (if empty)
     * @param string $name folder name to delete
     * @param bool $db_log
     * @return string
     * @throws Exception
     */
    public function deleteFolder(string $name, bool $db_log = false)
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        if (ftp_rmdir($this->connection, $name)) {
            if ($db_log) {
                $this->actionsLog('DELETE FOLDER', $name);
            }
            return json_encode(array('response' => 'success', 'action' => 'deleteFolder'));
        } else {
            throw new Exception("Could not delete $name");
        }
    }

    /**
     * Delete a file
     * @param string $name file to delete
     * @param bool $db_log log action to deleted_files table
     * @return string
     * @throws Exception
     */
    public function deleteFile(string $name, bool $db_log = false)
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        if (ftp_delete($this->connection, $name)) {
            if ($db_log) {
                $path_data = pathinfo($name);
                $db = $this->db_connect();
                $insert = $db->prepare('INSERT INTO `deleted_files` (`zone_name`, `file`, `dir`) VALUES (?, ?, ?)');
                $insert->execute([$this->storage_name, $path_data['basename'], $path_data['dirname']]);
            }
            return json_encode(array('response' => 'success', 'action' => 'deleteFile'));
        } else {
            throw new Exception("Could not delete $name");
        }
    }

    /**
     * Delete all files in a folder
     * @param string $dir delete all files in here
     * @return string
     * @throws Exception
     */
    public function deleteAllFiles(string $dir)
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        $url = (BunnyAPI::STORAGE_API_URL);
        $array = json_decode(file_get_contents("$url/$this->storage_name/" . $dir . "/?AccessKey=$this->access_key"), true);
        foreach ($array as $value) {
            if ($value['IsDirectory'] == false) {
                $file_name = $value['ObjectName'];
                $full_name = "$dir/$file_name";
                if (ftp_delete($this->connection, $full_name)) {
                    echo json_encode(array('response' => 'success', 'action' => 'deleteAllFiles'));
                } else {
                    throw new Exception("Could not delete $full_name");
                }
            }
        }
    }

    /**
     * Upload all files in a directory to a folder
     * @param string $dir upload all files from here
     * @param string $place upload the files to this location
     * @param int $mode
     * @param bool $db_log
     * @return string
     * @throws Exception
     */
    public function uploadAllFiles(string $dir, string $place, $mode = FTP_BINARY, $db_log = false)
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        $obj = scandir($dir);
        foreach ($obj as $file) {
            if (!is_dir($file)) {
                if (ftp_put($this->connection, "" . $place . "$file", "$dir/$file", $mode)) {
                    if ($db_log) {
                        $this->actionsLog('UPLOAD FILE', "" . $place . "$file", "$dir/$file");
                    }
                    echo json_encode(array('response' => 'success', 'action' => 'uploadAllFiles'));
                } else {
                    throw new Exception("Error uploading " . $place . "$file as " . $place . "/" . $file . "");
                }
            }
        }
    }

    /**
     * Returns array with file count and total size
     * @param string $dir directory to do count in
     * @return array
     * @throws Exception
     */
    public function dirSize(string $dir = '')
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        $url = (BunnyAPI::STORAGE_API_URL);
        $array = json_decode(file_get_contents("$url/$this->storage_name" . $dir . "/?AccessKey=$this->access_key"), true);
        $size = 0;
        $files = 0;
        foreach ($array as $value) {
            if ($value['IsDirectory'] == false) {
                $size = ($size + $value['Length']);
                $files++;
            }
        }
        return array('dir' => $dir, 'files' => $files, 'size_b' => $size, 'size_kb' => number_format(($size / 1024), 3),
            'size_mb' => number_format(($size / 1048576), 3), 'size_gb' => number_format(($size / 1073741824), 3));
    }

    /**
     * Return current directory
     * @return string
     * @throws Exception
     */
    public function currentDir()
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        return ftp_pwd($this->connection);
    }

    /**
     * Change working directory
     * @param string $moveto movement
     * @return string
     * @throws Exception
     */
    public function changeDir(string $moveto)
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        if (ftp_chdir($this->connection, $moveto)) {
            return json_encode(array('response' => 'success', 'action' => 'changeDir'));
        } else {
            throw new Exception("Error moving to $moveto");
        }
    }

    /**
     * Move to parent directory
     * @return string
     * @throws Exception
     */
    public function moveUpOne()
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        if (ftp_cdup($this->connection)) {
            return json_encode(array('response' => 'success', 'action' => 'moveUpOne'));
        } else {
            throw new Exception("Error moving to parent dir");
        }
    }

    /**
     * Renames a file
     * @note Downloads and re-uploads file as BunnyCDN has blocked ftp_rename()
     * @param string $old_dir current file directory
     * @param string $old_name current filename
     * @param string $new_dir rename file to directory
     * @param string $new_name rename file to
     * @param bool $db_log log change into file_history table
     * @return string
     * @throws Exception
     */
    public function rename(string $old_dir, string $old_name, string $new_dir, string $new_name, bool $db_log = false)
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        $path_data = pathinfo("" . $old_dir . "$old_name");
        $file_type = $path_data['extension'];
        if (ftp_get($this->connection, "tempRENAME.$file_type", "" . $old_dir . "$old_name", FTP_BINARY)) {
            if (ftp_put($this->connection, "$new_dir" . $new_name . "", "tempRENAME.$file_type", FTP_BINARY)) {
                if (ftp_delete($this->connection, "" . $old_dir . "$old_name")) {
                    if ($db_log) {
                        $db = $this->db_connect();
                        $insert = $db->prepare('INSERT INTO file_history (new_name, old_name, zone_name, new_dir, old_dir)
                                 VALUES (?, ?, ?, ?, ?)');
                        $insert->execute([$new_name, $old_name, $this->storage_name, $new_dir, $old_dir]);
                        unlink("tempRENAME.$file_type");//Delete temp file
                    }
                    return json_encode(array('response' => 'success', 'action' => 'rename'));
                }
            }
        } else {
            throw new Exception("Error renaming $old_name to $new_name");
        }
    }

    /**
     * Move a file
     * @param string $file 'path/filename.mp4'
     * @param string $move_to 'path/path/filename.mp4'
     * @param bool $db_log
     * @return string
     * @throws Exception
     */
    public function moveFile(string $file, string $move_to, bool $db_log = false)
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        if (ftp_rename($this->connection, $file, $move_to)) {
            if ($db_log) {
                $this->actionsLog('MOVE FILE', $file, $move_to);
            }
            return json_encode(array('response' => 'success', 'action' => 'moveFile'));
        } else {
            throw new Exception("Error renaming $file to $move_to");
        }
    }

    /**
     * Download a file
     * @param string $save_as Save as when downloaded
     * @param string $get_file File to download
     * @param int $mode
     * @param bool $db_log
     * @return string
     * @throws Exception
     */
    public function downloadFile(string $save_as, string $get_file, int $mode = FTP_BINARY, bool $db_log = false)
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        if (ftp_get($this->connection, $save_as, $get_file, $mode)) {
            if ($db_log) {
                $this->actionsLog('DOWNLOAD', $save_as, $get_file);
            }
            return json_encode(array('response' => 'success', 'action' => 'downloadFile'));
        } else {
            throw new Exception("Error downloading $get_file as $save_as");
        }
    }

    /**
     * Download all files in a directory
     * @param string $dir_dl_from directory to download all from
     * @param string $dl_into local folder to download into
     * @param int $mode FTP mode for download
     * @param bool $db_log
     * @return string
     * @throws Exception
     */
    public function downloadAll(string $dir_dl_from = '', string $dl_into = '', int $mode = FTP_BINARY, bool $db_log = false)
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        $url = (BunnyAPI::STORAGE_API_URL);
        $array = json_decode(file_get_contents("$url/$this->storage_name" . $dir_dl_from . "/?AccessKey=$this->access_key"), true);
        foreach ($array as $value) {
            if ($value['IsDirectory'] == false) {
                $file_name = $value['ObjectName'];
                if (ftp_get($this->connection, "" . $dl_into . "$file_name", $file_name, $mode)) {
                    if ($db_log) {
                        $this->actionsLog('DOWNLOAD', "" . $dl_into . "$file_name", $file_name);
                    }
                    echo json_encode(array('response' => 'success', 'action' => 'downloadAll'));
                } else {
                    throw new Exception("Error downloading $file_name to " . $dl_into . "$file_name");
                }
            }
        }
    }

    /**
     * Upload a file
     * @param string $upload File to upload
     * @param string $upload_as Save as when uploaded
     * @param int $mode
     * @param bool $db_log
     * @return string
     * @throws Exception
     */
    public function uploadFile(string $upload, string $upload_as, int $mode = FTP_BINARY, bool $db_log = false)
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        if (ftp_put($this->connection, $upload_as, $upload, $mode)) {
            if ($db_log) {
                $this->actionsLog('UPLOAD', $upload, $upload_as);
            }
            return json_encode(array('response' => 'success', 'action' => 'uploadFile'));
        } else {
            throw new Exception("Error uploading $upload as $upload_as");
        }
    }

    /**
     * Returns INT 1 for true and INT 0 for false
     * @param bool $bool
     * @return int
     */
    public function boolToInt(bool $bool)
    {
        if ($bool) {
            return 1;
        } else {
            return 0;
        }
    }

    /**
     * Set Json type header (Pretty print JSON in Firefox)
     */
    public function jsonHeader()
    {
        header('Content-Type: application/json');
    }

    /**
     * Returns official BunnyCDN data from storage instance
     * @return string
     * @throws Exception
     */
    public function listAllOG()
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        $url = (BunnyAPI::STORAGE_API_URL);
        return file_get_contents("$url/$this->storage_name/?AccessKey=$this->access_key");
    }

    /**
     * Returns formatted Json data about all files in location
     * @param string $location
     * @return string
     * @throws Exception
     */
    public function listFiles(string $location = '')
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        $url = (BunnyAPI::STORAGE_API_URL);
        $array = json_decode(file_get_contents("$url/$this->storage_name" . $location . "/?AccessKey=$this->access_key"), true);
        $items = array('storage_name' => "" . $this->storage_name, 'current_dir' => $location, 'data' => array());
        foreach ($array as $value) {
            if ($value['IsDirectory'] == false) {
                $created = date('Y-m-d H:i:s', strtotime($value['DateCreated']));
                $last_changed = date('Y-m-d H:i:s', strtotime($value['LastChanged']));
                if (isset(pathinfo($value['ObjectName'])['extension'])) {
                    $file_type = pathinfo($value['ObjectName'])['extension'];
                } else {
                    $file_type = null;
                }
                $file_name = $value['ObjectName'];
                $size_kb = floatval(($value['Length'] / 1024));
                $guid = $value['Guid'];
                $items['data'][] = array('name' => $file_name, 'file_type' => $file_type, 'size' => $size_kb, 'created' => $created,
                    'last_changed' => $last_changed, 'guid' => $guid);
            }
        }
        return json_encode($items);
    }

    /**
     * Returns formatted Json data about all folders in location
     * @param string $location
     * @return string
     * @throws Exception
     */
    public function listFolders(string $location = '')
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        $url = (BunnyAPI::STORAGE_API_URL);
        $array = json_decode(file_get_contents("$url/$this->storage_name" . $location . "/?AccessKey=$this->access_key"), true);
        $items = array('storage_name' => $this->storage_name, 'current_dir' => $location, 'data' => array());
        foreach ($array as $value) {
            $created = date('Y-m-d H:i:s', strtotime($value['DateCreated']));
            $last_changed = date('Y-m-d H:i:s', strtotime($value['LastChanged']));
            $foldername = $value['ObjectName'];
            $guid = $value['Guid'];
            if ($value['IsDirectory'] == true) {
                $items['data'][] = array('name' => $foldername, 'created' => $created,
                    'last_changed' => $last_changed, 'guid' => $guid);
            }
        }
        return json_encode($items);
    }

    /**
     * Returns formatted Json data about all files and folders in location
     * @param string $location
     * @return string
     * @throws Exception
     */
    function listAll(string $location = '')
    {
        if (is_null($this->connection))
            throw new Exception("zoneConnect() is not set");
        $url = (BunnyAPI::STORAGE_API_URL);
        $array = json_decode(file_get_contents("$url/$this->storage_name" . $location . "/?AccessKey=$this->access_key"), true);
        $items = array('storage_name' => "" . $this->storage_name, 'current_dir' => $location, 'data' => array());
        foreach ($array as $value) {
            $created = date('Y-m-d H:i:s', strtotime($value['DateCreated']));
            $last_changed = date('Y-m-d H:i:s', strtotime($value['LastChanged']));
            $file_name = $value['ObjectName'];
            $guid = $value['Guid'];
            if ($value['IsDirectory'] == true) {
                $file_type = null;
                $size_kb = null;
            } else {
                if (isset(pathinfo($value['ObjectName'])['extension'])) {
                    $file_type = pathinfo($value['ObjectName'])['extension'];
                } else {
                    $file_type = null;
                }
                $size_kb = floatval(($value['Length'] / 1024));
            }
            $items['data'][] = array('name' => $file_name, 'file_type' => $file_type, 'size' => $size_kb, 'is_dir' => $value['IsDirectory'], 'created' => $created,
                'last_changed' => $last_changed, 'guid' => $guid);
        }
        return json_encode($items);
    }

    /**
     * Closes FTP connection (Optional use)
     * @return string
     * @throws Exception
     */
    public function closeConnection()
    {
        if (ftp_close($this->connection)) {
            return json_encode(array('response' => 'success', 'action' => 'closeConnection'));
        } else {
            throw new Exception("Error closing connection to " . (BunnyAPI::HOSTNAME) . "");
        }
    }

    /**
     * @note Below begins the MySQL database functions
     * @note These are completely optional
     * @note Please ensure that you have edited db_connect() beginning up at line 56
     * @note Also ran MySQL_database.sql file to your database
     */

    /**
     * Inserts pull zones into `pullzones` database table
     * @return string
     */
    public function insertPullZones()
    {
        $db = $this->db_connect();
        $data = json_decode($this->listPullZones(), true);
        foreach ($data as $aRow) {
            $insert = $db->prepare('INSERT INTO `pullzones` (`id`, `name`, `origin_url`,`enabled`, `bandwidth_used`, `bandwidth_limit`,`monthly_charge`, `storage_zone_id`, `zone_us`, `zone_eu`, `zone_asia`, `zone_sa`, `zone_af`)
                   VALUES (:id, :name, :origin, :enabled, :bwdth_used, :bwdth_limit, :charged, :storage_zone_id, :zus, :zeu, :zasia, :zsa, :zaf)
                    ON DUPLICATE KEY UPDATE `enabled` = :enabled, `bandwidth_used` = :bwdth_used,`monthly_charge` = :charged, `zone_us` = :zus, `zone_eu` = :zeu,`zone_asia` = :zasia, `zone_sa` = :zsa, `zone_af` = :zaf');
            $insert->execute([
                ':id' => $aRow['Id'],
                ':name' => $aRow['Name'],
                ':origin' => $aRow['OriginUrl'],
                ':enabled' => $this->boolToInt($aRow['Enabled']),
                ':bwdth_used' => $aRow['MonthlyBandwidthUsed'],
                ':bwdth_limit' => $aRow['MonthlyBandwidthLimit'],
                ':charged' => $aRow['MonthlyCharges'],
                ':storage_zone_id' => $aRow['StorageZoneId'],
                ':zus' => $this->boolToInt($aRow['EnableGeoZoneUS']),
                ':zeu' => $this->boolToInt($aRow['EnableGeoZoneEU']),
                ':zasia' => $this->boolToInt($aRow['EnableGeoZoneASIA']),
                ':zsa' => $this->boolToInt($aRow['EnableGeoZoneSA']),
                ':zaf' => $this->boolToInt($aRow['EnableGeoZoneAF'])
            ]);
        }
        return json_encode(array('response' => 'success', 'action' => 'insertPullZoneLogs'));
    }

    /**
     * Inserts storage zones into `storagezones` database table
     * @return string
     */
    public function insertStorageZones()
    {
        $db = $this->db_connect();
        $data = json_decode($this->listStorageZones(), true);
        foreach ($data as $aRow) {
            if ($aRow['Deleted'] == false) {
                $enabled = 1;
            } else {
                $enabled = 0;
            }
            $insert = $db->prepare('INSERT INTO `storagezones` (`id`, `name`, `storage_used`, `enabled`, `files_stored`, `date_modified`)
                VALUES (:id, :name, :storage_used, :enabled, :files_stored, :date_modified)
                ON DUPLICATE KEY UPDATE `storage_used` = :storage_used, `enabled` = :enabled,`files_stored` = :files, `date_modified` = :date_modified');
            $insert->execute([
                ':id' => $aRow['Id'],
                ':name' => $aRow['Name'],
                'storage_used' => $aRow['StorageUsed'],
                ':enabled' => $enabled,
                ':files_stored' => $aRow['FilesStored'],
                ':date_modified' => $aRow['DateModified']
            ]);
        }
        return json_encode(array('response' => 'success', 'action' => 'insertPullZoneLogs'));
    }

    /**
     * Inserts pull zone logs into `logs` database table
     * @param int $id
     * @param string $date
     * @return string
     */
    public function insertPullZoneLogs(int $id, string $date)
    {
        $db = $this->db_connect();
        $data = $this->pullZoneLogs($id, $date);
        foreach ($data as $aRow) {
            $insert_overview = $db->prepare('INSERT IGNORE INTO `log_main` (`zid`, `rid`, `result`, `referer`, `file_url`, `datetime`) VALUES (?, ?, ?, ?, ?, ?)');
            $insert_overview->execute([$aRow['zone_id'], $aRow['request_id'], $aRow['cache_result'], $aRow['referer'],
                $aRow['file_url'], $aRow['datetime']]);
            $insert_main = $db->prepare('INSERT IGNORE INTO `log_more` (`zid`, `rid`, `status`, `bytes`, `ip`,
                `user_agent`, `cdn_dc`, `country_code`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $insert_main->execute([$aRow['zone_id'], $aRow['request_id'], $aRow['status'], $aRow['bytes'], $aRow['ip'],
                $aRow['user_agent'], $aRow['cdn_dc'], $aRow['country_code']]);
        }
        return json_encode(array('response' => 'success', 'action' => 'insertPullZoneLogs'));
    }

    /**
     * Action logger for broader actions
     * @param string $task
     * @param string $file
     * @param string|null $file_other
     */
    public function actionsLog(string $task, string $file, string $file_other = NULL)
    {
        $db = $this->db_connect();
        $insert = $db->prepare('INSERT INTO `actions` (`task`, `zone_name`, `file`, `file_other`) VALUES (?, ?, ?, ?)');
        $insert->execute([$task, $this->storage_name, $file, $file_other]);
    }

}
