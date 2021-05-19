<?php
// www.IQB.hu-berlin.de
// 2018
// license: MIT

// preflight OPTIONS-Request bei CORS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit();

} else {
    require_once('vo_code/DBConnection.php');
    $return = false;
    $errorCode = 0;
    $myDBConnection = new DBConnection();

    if ($myDBConnection->isError()) {
        $errorCode = 503;

    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        $sessionToken = $data["t"];

        if (!isset($sessionToken) || empty($sessionToken)) {
            $errorCode = 401;

        } else {
            $return = $myDBConnection->logout($sessionToken);
        }
    }

    unset($myDBConnection);

    if ($errorCode > 0) {
        http_response_code($errorCode);

    } else {
        echo(json_encode($return));
    }
}
