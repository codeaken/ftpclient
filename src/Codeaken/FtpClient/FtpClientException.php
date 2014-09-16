<?php
namespace Codeaken\FtpClient;

class FtpClientException extends \Exception
{
    const CONNECTION_FAILED = 1;
    const LOGIN_FAILED = 2;
    const REQUIRES_CONNECTION = 3;
    const REQUIRES_LOGIN = 4;

    const CONNECTION_FAILED_MSG = 'Failed connecting to the FTP server';
    const LOGIN_FAILED_MSG = 'Login failed, wrong username or password';
    const REQUIRES_CONNECTION_MSG = 'This method requires an active connection';
    const REQUIRES_LOGIN_MSG = 'This method requires a successful login';
}
