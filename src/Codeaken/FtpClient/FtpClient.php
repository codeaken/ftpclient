<?php
namespace Codeaken\FtpClient;

class FtpClient
{
    private $ftpResource;
    private $features;
    private $pasv;
    private $directory;
    private $loggedIn;
    private $connected;

    public function __construct()
    {
        // Setup initial state
        $this->reset();
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function changeDirectory($directory)
    {
        $this->directory = $this->buildAbsolutePath($directory);

        return $this->directory;
    }

    public function changeFile($remoteFile, callable $callback)
    {
        // Require a connection and a login
        $this->validateState();

        $remoteFile = $this->buildAbsolutePath($remoteFile);

        // Download the file to a temporary location
        $fileContents = $this->downloadContents($remoteFile);

        // Get the new contents
        $newContents = call_user_func(
            $callback,
            $fileContents
        );

        // Re-upload the file
        if (false !== $newContents) {
            $this->uploadContents($newContents, $remoteFile);
        }
    }

    public function connect($hostname, $port = 21, $pasv = true, $timeout = 10)
    {
        if ($this->isConnected()) {
            // Clean up any previous connection that has not been explicitly
            // closed
            $this->disconnect();
        }

        // Connect to the server
        $this->ftpResource = @ftp_connect($hostname, $port, $timeout);

        if (false === $this->ftpResource) {
            throw new FtpClientException(
                FtpClientException::CONNECTION_FAILED_MSG,
                FtpClientException::CONNECTION_FAILED
            );
        }

        // Set the connected state
        $this->connected = true;
        $this->pasv = $pasv;

        // Get what features this ftp server supports
        $this->getFeatures();
    }

    public function createDirectory($remoteDir)
    {
        // Require a connection and a login
        $this->validateState();

        $remoteDir = $this->buildAbsolutePath($remoteDir);

        return @ftp_mkdir($this->ftpResource, $remoteDir);
    }

    public function deleteDirectory($remoteDir)
    {
        // Require a connection and a login
        $this->validateState();

        $remoteDir = $this->buildAbsolutePath($remoteDir);

        foreach ($this->listDirectory($remoteDir) as $item) {
            switch ($item['type']) {
                case 'file':
                    $this->deleteFile("{$remoteDir}/{$item['name']}");
                    break;

                case 'directory':
                    $this->deleteDirectory("{$remoteDir}/{$item['name']}");
                    break;
            }
        }

        @ftp_rmdir($this->ftpResource, $remoteDir);
    }

    public function deleteFile($remoteFile)
    {
        // Require a connection and a login
        $this->validateState();

        $remoteFile = $this->buildAbsolutePath($remoteFile);

        return @ftp_delete($this->ftpResource, $remoteFile);
    }

    public function disconnect()
    {
        if (is_resource($this->ftpResource)) {
            @ftp_close($this->ftpResource);
        }

        $this->reset();
    }

    public function downloadContents($remoteFile)
    {
        // Require a connection and a login
        $this->validateState();

        $remoteFile = $this->buildAbsolutePath($remoteFile);

        $stream = fopen('php://memory', 'r+');

        @ftp_fget(
            $this->ftpResource,
            $stream,
            $remoteFile,
            FTP_BINARY
        );

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }

    public function downloadDirectory($remoteDir, $localDir = '.')
    {
        // Require a connection and a login
        $this->validateState();

        $remoteDir = $this->buildAbsolutePath($remoteDir);

        if (empty($localDir) || '.' == $localDir) {
            $localDir = getcwd();
        }
        $localBaseDir = $localDir;

        if (substr($remoteDir, -1) != '/') {
            $directory = basename($remoteDir);

            $localBaseDir .= "/{$directory}";

            if ( ! file_exists($localBaseDir)) {
                mkdir($localBaseDir);
            }
        }

        foreach ($this->listDirectory($remoteDir) as $item) {
            switch ($item['type']) {
                case 'file':
                    $this->downloadFile(
                        "{$remoteDir}/{$item['name']}",
                        "{$localBaseDir}/{$item['name']}"
                    );
                    break;

                case 'directory':
                    $this->downloadDirectory(
                        "{$remoteDir}/{$item['name']}",
                        "{$localBaseDir}"
                    );
                    break;
            }
        }
    }

    public function downloadFile($remoteFile, $localFile)
    {
        // Require a connection and a login
        $this->validateState();

        $remoteFile = $this->buildAbsolutePath($remoteFile);

        return @ftp_get(
            $this->ftpResource,
            $localFile,
            $remoteFile,
            FTP_BINARY
        );
    }

    public function listDirectory($remoteDir = '.')
    {
        // Require a connection and a login
        $this->validateState();

        $remoteDir = $this->buildAbsolutePath($remoteDir);

        $rawList = @ftp_rawlist($this->ftpResource, $remoteDir);

        if (false === $rawList) {
            return false;
        }

        return $this->parseDirectoryListing($rawList);
    }

    public function login($username, $password)
    {
        // Require a connection to proceed
        $this->validateState(false);

        $loginResult = @ftp_login(
            $this->ftpResource,
            $username,
            $password
        );

        if (!$loginResult) {
            throw new FtpClientException(
                FtpClientException::LOGIN_FAILED_MSG,
                FtpClientException::LOGIN_FAILED
            );
        }

        $this->loggedIn = true;

        // Set the transfer mode to passive or active
        // Note: Even though we receive the state of this value in the connect
        // function it must be run after a successful login to have any effect.
        $this->setPasv($this->pasv);
    }

    public function moveFile($remoteFileFrom, $remoteFileTo)
    {
        // Require a connection and a login
        $this->validateState();

        $remoteFileFrom = $this->buildAbsolutePath($remoteFileFrom);
        $remoteFileTo   = $this->buildAbsolutePath($remoteFileTo);

        $returnVal = @ftp_rename(
            $this->ftpResource, $remoteFileFrom, $remoteFileTo
        );

        return $returnVal;
    }

    public function setPasv($state)
    {
        // Require a connection and a login
        $this->validateState();

        $this->pasv = $state;

        @ftp_pasv($this->ftpResource, $state);
    }

    public function uploadContents($contents, $remoteFile)
    {
        // Require a connection and a login
        $this->validateState();

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $remoteFile = $this->buildAbsolutePath($remoteFile);

        @ftp_fput($this->ftpResource, $remoteFile, $stream, FTP_BINARY);

        fclose($stream);
    }

    public function uploadDirectory($localDir)
    {
        // Require a connection and a login
        $this->validateState();

        // Setup directory iterator
        $directoryFlags = FilesystemIterator::SKIP_DOTS |
                          FilesystemIterator::KEY_AS_PATHNAME |
                          FilesystemIterator::CURRENT_AS_FILEINFO;
        $directory = new RecursiveDirectoryIterator(
            $localDir,
            $directoryFlags
        );

        // Setup the iterator that will iterate over the directory iterator
        $iterator = new RecursiveIteratorIterator(
            $directory,
            RecursiveIteratorIterator::SELF_FIRST
        );

        // This is the base remote directory from where the upload starts
        $baseRemoteDir  = $this->buildAbsolutePath('.');
        $startRemoteDir = $this->buildAbsolutePath('.');

        if (substr($localDir, -1) != '/') {
            // We should create the last directory of the local directory
            $directory = basename($localDir);

            $this->createDirectory($directory);

            $baseRemoteDir = $this->buildAbsolutePath($directory);
        }

        foreach ($iterator as $fileInfo) {

            if ($fileInfo->isDir()) {
                $relativeSource = ltrim(substr($fileInfo->getPathname(), strlen($localDir)), '/');
                $remoteDir = $baseRemoteDir . '/' . $relativeSource;

                // Create the directory
                $this->createDirectory($remoteDir);
            }

            if ($fileInfo->isFile()) {
                $relativeSource = substr($fileInfo->getPath(), strlen($localDir));
                $remoteDir = $baseRemoteDir . '/' . $relativeSource;

                $this->changeDirectory($remoteDir);
                $this->uploadFile($fileInfo->getPathname());
            }
        }

        // Restore the current directory to where we started
        $this->changeDirectory($startRemoteDir);
    }

    public function uploadFile($localFile, $remoteFile = '')
    {
        // Require a connection and a login
        $this->validateState();

        $localFileInfo = new SplFileInfo($localFile);

        if (empty($remoteFile)) {
            $remoteFile = $this->buildAbsolutePath($localFileInfo->getFilename());
        }

        return @ftp_put(
            $this->ftpResource,
            $remoteFile,
            $localFileInfo->getPathname(),
            FTP_BINARY
        );
    }

    private function buildAbsolutePath($path)
    {
        if ('.' == $path || empty($path)) {
            return rtrim($this->directory, '/');
        }

        if ($this->isAbsolutePath($path)) {
            return trim($path);
        }

        return rtrim($this->directory, '/') . '/' . $path;
    }

    private function getFeatures()
    {
        $rawFeat = @ftp_raw($this->ftpResource, 'FEAT');

        if (substr($rawFeat[0], 0, 3) != '211') {
            return;
        }

        unset($rawFeat[0]);
        unset($rawFeat[count($rawFeat)]);

        foreach ($rawFeat as $feature) {
            $this->features[] = trim($feature);
        }
    }

    private function isAbsolutePath($path)
    {
        $path = trim($path);

        return ($path[0] == '/');
    }

    private function isConnected()
    {
        return ($this->connected && is_resource($this->ftpResource));
    }

    private function isLoggedIn()
    {
        return $this->loggedIn;
    }

    private function parseDirectoryListing($rawList)
    {
        $rawList = implode("\n", $rawList);
        preg_match_all(
            '/^([drwx+-]{10})\s+(\d+)\s+(\w+)\s+(\w+)\s+(\d+)\s+(.{12}) (.*)$/m',
            $rawList,
            $matches,
            PREG_SET_ORDER
        );

        $listing = [];
        foreach ($matches as $match) {
            // Check if this is a file or a directory
            $type = 'file';

            if ('d' == $match[1][0]) {
                $type = 'directory';
            }

            // Extract the permissions
            list($user, $group, $other) = str_split(substr($match[1], 1), 3);
            $permissions = [
                'user'  => $user,
                'group' => $group,
                'other' => $other,
            ];

            $listing[] = [
                'type' => $type,
                'permissions' => $permissions,
                'owner' => $match[3],
                'group' => $match[4],
                'size'  => $match[5],
                'date'  => $match[6],   // @todo parse this
                'name'  => $match[7]
            ];
        }

        return $listing;
    }

    private function reset()
    {
        $this->ftpResource = null;
        $this->features = [];
        $this->pasv = true;
        $this->directory = '/';
        $this->loggedIn = false;
        $this->connected = false;
    }

    private function validateState($requireLogin = true)
    {
        // Always require an established connection
        if ( ! $this->isConnected()) {
            throw new FtpClientException(
                FtpClientException::REQUIRES_CONNECTION_MSG,
                FtpClientException::REQUIRES_CONNECTION
            );
        }

        if ($requireLogin) {
            if ( ! $this->isLoggedIn()) {
                throw new FtpClientException(
                    FtpClientException::REQUIRES_LOGIN_MSG,
                    FtpClientException::REQUIRES_LOGIN
                );
            }
        }
    }
}