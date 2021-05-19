<?php
// www.IQB.hu-berlin.de
// Bărbulescu, Stroescu, Mechtel
// 2018
// license: MIT

// preflight OPTIONS-Request bei CORS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit();

} else {
    require_once('vo_code/DBConnection.php');
    $return = [
        'token' => '',
        'name' => '',
        'is_superadmin' => false
    ];
    $errorCode = 0;

    error_log("Database Connection attempt ...");
    $dbConnection = new DBConnection();

    if ($dbConnection->isError()) {
        $errorCode = 503;
        error_log("Database Connection couldn't established: " . $dbConnection->errorMsg);

    } else {
        error_log("Database Connection successful!");
        $data = json_decode(file_get_contents('php://input'), true);
        $userName = $data["n"];
        $userPassword = $data["p"];

        if (!isset($userName) || !isset($userPassword)) {
            error_log("User name or password missing!");
            $errorCode = 401;

        } else {
            $login = $dbConnection->login($userName, $userPassword);

            if (!isset($login)) {
                error_log("Login failed!");
                $errorCode = 401;

            } else {
                $return = [
                    'token' => $login["sessionToken"],
                    'name' => $login["user"]["name"],
                    'is_superadmin' => $login["user"]["is_superadmin"]
                ];
            }
        }
    }

    unset($dbConnection);

    if ($errorCode > 0) {
        http_response_code($errorCode);

    } else {
        echo(json_encode($return));
    }

}
