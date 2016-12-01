<?php

class qtype_javaunittest_question_executor {
    public $question;

    function __construct($q) {
        $this->question = $q;
    }

    function local_execute($response) {
        throw new Exception("qtype_javaunittest: `local_execute' unimplemented in executor ");
    }
    
    /**
     * Here happens everything important for remote executing.
     *
     * @param array $response the response of the student
     * @return array result
     */
    function remote_execute ( $response ) {
        global $USER;
        $cfg_plugin = get_config ( 'qtype_javaunittest' );

        $post = array (
                'PHP_AUTH_USER' => $cfg_plugin->remoteserver_user,
                'PHP_AUTH_PW' => $cfg_plugin->remoteserver_password,
                'clientversion' => $cfg_plugin->version,
                'uid' => $USER->id,
                'qid' => $this->question->id,
                'attemptid' => $this->question->questionattemptid,
                'testclassname' => $this->question->testclassname,
                'studentscode' => $response['answer'],
                'junitcode' => $this->question->junitcode,
                'memory_xmx' => $cfg_plugin->memory_xmx,
                'memory_limit_output' => $cfg_plugin->memory_limit_output,
                'timeoutreal' => $cfg_plugin->timeoutreal
        );
        if ( !empty( $this->question->signature ) ) {
            $post['signature'] = $this->question->signature;
        }

        $curlHandle = curl_init ();
        curl_setopt ( $curlHandle, CURLOPT_URL, $cfg_plugin->remoteserver );
        curl_setopt ( $curlHandle, CURLOPT_POST, 1 );
        curl_setopt ( $curlHandle, CURLOPT_VERBOSE, 0 );
        curl_setopt ( $curlHandle, CURLOPT_POSTFIELDS, $post );
        curl_setopt ( $curlHandle, CURLOPT_FOLLOWLOCATION, 1 );
        curl_setopt ( $curlHandle, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt ( $curlHandle, CURLOPT_AUTOREFERER, 1 );
        curl_setopt ( $curlHandle, CURLOPT_MAXREDIRS, 10 );
        curl_setopt ( $curlHandle, CURLOPT_CONNECTTIMEOUT, 5 );
        curl_setopt ( $curlHandle, CURLOPT_TIMEOUT, 2 * $cfg_plugin->timeoutreal );
        curl_setopt ( $curlHandle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
        $result = curl_exec ( $curlHandle );
        $HTTPStatusCode = curl_getinfo ( $curlHandle, CURLINFO_HTTP_CODE );
        curl_close ( $curlHandle );

        if ( $HTTPStatusCode != 200 ) {
            return array (
                    'error' => true,
                    'errortype' => 'REMOTE_SERVER_ERROR',
                    'message' => $result
            );
        }

        $json = json_decode ( $result, true );
        if ( $json === null ) {
            return array (
                    'error' => true,
                    'errortype' => 'REMOTE_SERVER_ERROR',
                    'message' => 'JSON decoding error'
            );
        }

        return $json;
    }

    /**
     * Assistent function to create a directory inclusive missing top directories.
     *
     * @param string $folder the absolute path
     * @return boolean true on success
     */
    function mkdir_recursive ( $folder ) {
        global $CFG;
        if ( is_dir ( $folder ) ) {
            return true;
        }
        if ( !$this->mkdir_recursive ( dirname ( $folder ) ) ) {
            return false;
        }
        // calculate directory permission for temporary directories
        // (get moodle config value, get digits, set first bit for temporary bit "1", create decimal)
        $dirpermissionstr = decoct ( $CFG->directorypermissions );
        $dirpermissionint;
        if ( strlen ( $dirpermissionstr ) == 3 ) {
            $dirpermissionstr = "1" . $dirpermissionstr;
        } else if ( strlen ( $dirpermissionstr ) == 4 ) {
            if ( $dirpermissionstr[0] == 0 )
                $dirpermissionstr[0] = 1;
        } else {
            throw new Exception ( "qtype_javaunittest: moodle config directory permissions settings seems broken (not 3-4 digits)<br>\n" );
        }
        $dirpermissionint = intval ( $dirpermissionstr, 8 );
        $rc = mkdir ( $folder, $dirpermissionint );
        if ( !$rc ) {
            throw new Exception ( "qtype_javaunittest: cannot create directory " . $folder . "<br>\n" );
        }
        return $rc;
    }

    /**
     * Assistent function to delete a directory tree.
     *
     * @param string $dir the absolute path
     * @return boolean true on success, false else
     */
    function delTree ( $dir ) {
        $files = array_diff ( scandir ( $dir ), array (
                '.',
                '..'
        ) );
        foreach ( $files as $file ) {
            (is_dir ( "$dir/$file" )) ? $this->delTree ( "$dir/$file" ) : unlink ( "$dir/$file" );
        }
        $rc = rmdir ( $dir );
        return $rc;
    }

}

?>