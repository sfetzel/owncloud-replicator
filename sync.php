<?php
 
use Sabre\DAV\Client;
 
include 'vendor/autoload.php';
 
class WebdavFolderEntry
{
    public $isDir;
    public $name;
    public $lastModified;
    public $relativeUrl;
}
 
class WebdavSync
{
    private $masterClient;
    private $backupClient;
 
    public function __construct($masterClient, $backupClient)
    {
        $this->masterClient = $masterClient;
        $this->backupClient = $backupClient;
    }
 
    public function getDirectoryEntries($client, $path)
    {
        $entries = $client->propFind($path, array(
            '{DAV:}displayname',
            '{DAV:}getlastmodified',
            '{DAV:}resourcetype'
        ), 1);
        unset($entries[$path]);
       
        $folderEntries = Array();
        foreach($entries as $entry => $properties)
        {
            $folderEntry = new WebdavFolderEntry();
            $folderEntry->isDir = $properties["{DAV:}resourcetype"] !== NULL &&
                $properties["{DAV:}resourcetype"]->is("{DAV:}collection");             
            $folderEntry->lastModified = strtotime($properties["{DAV:}getlastmodified"]);
            $folderEntry->name = basename($entry);
            $folderEntry->relativeUrl = $entry;
            $folderEntries[basename($entry)] = $folderEntry;
        }
        return $folderEntries;
    }
 
    public function copyToBackup($path)
    {
        $fileContents = $this->masterClient->request('GET', $path);
        $result = $this->backupClient->request('PUT', $path, $fileContents["body"]);
        echo " * Copying $path to backup (".strlen($fileContents["body"])." bytes)..";
        if($result["statusCode"] != 201 && $result["statusCode"] != 202)
        {
            echo "failed (status ".$result["statusCode"]."): ".$result["body"];
        }
        echo "\n";
    }
   
    public function createFolderOnBackup($path)
    {
        $this->backupClient->request('MKCOL', $path);
    }
 
    public function comparePath($path)
    {
        echo " * Comparing $path";
        $masterEntries = $this->getDirectoryEntries($this->masterClient, $path);
        $backupEntries = $this->getDirectoryEntries($this->backupClient, $path);
        echo "\n";
       
        foreach($masterEntries as $fileName => $masterEntry)
        {
            if(isset($backupEntries[$fileName]) &&
                $backupEntries[$fileName]->isDir
                !== $masterEntry->isDir)
            {
                // delete
                $this->backupClient->request('DELETE', $backupEntries[$fileName]->relativeUrl);
                echo " ** Deleting ".$backupEntries[$fileName]->relativeUrl." on backup: dir/file conflict\n";
            }
       
            if($masterEntry->isDir)
            {
                if(!isset($backupEntries[$fileName]))
                {
                    $this->createFolderOnBackup($masterEntry->relativeUrl);
                }
                $this->comparePath($masterEntry->relativeUrl);
            }
            else
            {
                if(isset($backupEntries[$fileName]))
                {
                    if($backupEntries[$fileName]->lastModified <
                        $masterEntry->lastModified)
                    {
                        echo " ** ".$masterEntry->relativeUrl." is newer, copying to backup\n";
                        // backup is older! if file, copy
                        $this->copyToBackup($masterEntry->relativeUrl);
                    }
                }
                else
                {
                    echo " ** ".$masterEntry->relativeUrl." does not exist on backup, copying\n";
                    $this->copyToBackup($masterEntry->relativeUrl);
                }
            }
        }
    }
}
 
$masterServerSettings = array(
    'baseUri' => 'https://dev.get-the-solution.net/owncloud/remote.php/webdav',
    'userName' => 'simon',
    'password' => 'catsaregreat'
);
 
$backupServerSettings = array(
    'baseUri' => 'https://dev2.get-the-solution.net/owncloud/remote.php/webdav',
    'userName' => 'simon',
    'password' => 'dogsaredumb'
);
$backupServerClient = new Client($backupServerSettings);
 
// for servers with self signed certificates and ... wrong host settings
//$backupServerClient->addCurlSetting(CURLOPT_SSL_VERIFYPEER, FALSE);
//$backupServerClient->addCurlSetting(CURLOPT_SSL_VERIFYHOST, FALSE);
 
$sync = new WebdavSync(new Client($masterServerSettings),
    $backupServerClient);
   
$sync->comparePath("/owncloud/remote.php/webdav/");
 
