<?php
//Start Session Storage
session_start();

//Turn off PHP error reporting
error_reporting(0);

include "../configdb.php";

$dbhandle=new mysqli(HOSTNAME, USERNAME, PASSWORD, DATABASE) or die ("Unable to Connect to the Database");

$db = DATABASE;

$sqlQuery=json_decode(file_get_contents("php://input"));

//Execute this function if sql query is SELECT
if (strpos($sqlQuery->sql, 'SELECT') !== false) {
    
    $sql=$sqlQuery->sql;
    $rs=$dbhandle->query($sql);
    
    if ($dbhandle->error) {
        print_r($dbhandle->error);
    } else {
        while($row=$rs->fetch_array(MYSQLI_ASSOC)){
            $data[]=$row;
        }
        
        //Show All Tables in the database
        $result = $dbhandle->query("SHOW TABLES FROM $db");

        while($row=mysqli_fetch_row($result)){
            if(strpos($sql, $row[0]) !== false) {
                $table = $row[0];
            }
        }
        
        //Remove cookies when web app is loaded
        setcookie("priKey", null, -1, "/");
        
        //Check Primary and Unique Key of Table
        $rsPriKey = $dbhandle->query("DESCRIBE $table");
        while($row=$rsPriKey->fetch_array(MYSQLI_ASSOC)){
            if ($row['Key'] == "PRI") {
                //Store Primary Key as cookie for client side scripting purpose
                setcookie("priKey", $row['Field'], 0, "/");
                $tableDetail[] = $row;
            } else {
                $tableDetail[] = $row;
            }
        }
        
        //Set Session Storage
        $_SESSION['table'] = $table;
        $_SESSION['data'] = $data;
        $_SESSION['tableDetail'] = $tableDetail;
        $_SESSION['selectQuery'] = $sqlQuery->sql;
        
        $resultData['data'] = $data;
        $resultData['keyTable'] = $tableDetail;
        
        print json_encode($resultData, JSON_NUMERIC_CHECK);
    }
    
} else if (strpos($sqlQuery->sqlType, 'INSERT') !== false || strpos($sqlQuery->sqlType, 'UPDATE') !== false) {
    $table = $_SESSION['table'];
    $data = $_SESSION['data'];
    $tableDetail = $_SESSION['tableDetail'];
    $selectQuery = $_SESSION['selectQuery'];
    $colTitle = "";
    $colField = "";
    $result = $dbhandle->query("DESCRIBE $table");
    
    while($row=$result->fetch_array(MYSQLI_ASSOC)){
        
        foreach($sqlQuery as $key => $value) {
            if ($key != "sqlType") {
                //Check if the column field same as the key of data from client side
                if ($key == $row['Field']) {
                    $colTitle .= $key. ', ';
                    $length = preg_replace("/[^0-9]/","",$row['Type']);
                    
                    //Check if MySQL table's column type is numeric
                    if (preg_match("/(tinyint|smallint|mediumint|int|bigint|decimal|float|double)/i", $row['Type'])){
                        //Check if the data is numeric
                        if(is_numeric($value)) {
                            //Check the length of the data
                            if (strlen($value) <= $length) {
                                //Check if the column is primary and unique key
                                if (($row['Key'] == "PRI" || $row['Key'] == "UNI") && strpos($sqlQuery->sqlType, 'INSERT') !== false) {
                                    $existed = false;
                                    foreach ($data as $colVal) {
                                        if ($colVal[$key] == $value) {
                                            $msg[] = "The data is existed. Please insert another data.";
                                            $existed = true;
                                        } 
                                    }
                                    //Store value into variable if the data is not existed
                                    if ($existed == false) {
                                        $msg[] = "Validated";
                                        $colField .= "'".$value."', ";
                                        $updateValue .= "$key = '".$value."', ";
                                    }
                                    
                                } else {
                                    $msg[] = "Validated";
                                    $colField .= "'".$value."', ";
                                    $updateValue .= "$key = '".$value."', ";
                                }
                                
                            } else {
                                $msg[] = "Please insert your input less than $length";
                            }
                        } else {
                            $msg[] = "Please insert number only";  
                        }
                    } else {
                        //Check the length of the data
                        if (strlen($value) <= $length) {
                            $msg[] = "Validated";
                            $colField .= "'".$value."', ";
                            $updateValue .= "$key = '".$value."', ";
                        } else {
                            $msg[] = "Please insert your input less than $length";
                        }
                    }
                }
            }
        }
    }
    
    $queryMode = true;
    
    for ($i = 0; $i < sizeof($msg); $i++) {
        if ($msg[$i] != "Validated") {
            $queryMode = false;
        }
    }
    
    $colTitle = substr(trim($colTitle), 0, -1);
    $colField = substr(trim($colField), 0, -1);
    $updateValue = substr(trim($updateValue), 0, -1);
    
    if (strpos($sqlQuery->sqlType, 'INSERT') !== false) {
        $query = "INSERT INTO $table ($colTitle) VALUES ($colField)";
        if ($queryMode == true) {
            $dbhandle->query($query);
            print "Inserted";
        } else {
            print json_encode($msg);
        }
    } else if (strpos($sqlQuery->sqlType, 'UPDATE') !== false) {
        $priKey = $_COOKIE['priKey'];
        $priVal = $sqlQuery->priVal;
        
        if ($priKey == null) {
            $result = $dbhandle->query($selectQuery);
            while ($row=$result->fetch_array(MYSQLI_ASSOC)) {
                $selectedData[] = $row;
            }
            foreach ($selectedData[0] as $key => $value) {
                $priKey = $key;
                break;
            }
        }
        
        $query = "UPDATE $table SET $updateValue WHERE $priKey='".$priVal."'";
        
        if ($queryMode == true) {
            $dbhandle->query($query);
            if ($dbhandle->error) {
                $errorMsg = preg_split("/for key '/", $dbhandle->error);
                $errorMsg = substr(trim($errorMsg[1]), 0, -1);
                print_r($errorMsg);
            } else {
                print "Updated";  
            }
        } else {
            print json_encode($msg);
        }
    }

} else if (strpos($sqlQuery->sqlType, 'DELETE') !== false) {
    $table = $_SESSION['table'];
    $deleteValue = "";
    $result = $dbhandle->query("DESCRIBE $table");
    
    while ($row=$result->fetch_array(MYSQLI_ASSOC)) {
        foreach ($sqlQuery as $key => $value) {
            if ($key != "sqlType") {
                if ($key == $row['Field']) {
                    if ($value != null) {
                        $deleteValue .= "$key = '".$value."' AND ";
                    }
                }
            }
        }
    }
    
    $deleteValue = substr(trim($deleteValue), 0, -3);
    
    $query = "DELETE FROM $table WHERE $deleteValue";
    
    $dbhandle->query($query);

    print "success";
}

?>