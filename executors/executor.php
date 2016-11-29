<?php

class qtype_javaunittest_question_executor {
    public $question;

    function __construct($q) {
        $this->question = $q;
    }

    /**
     * Here happens everything important. Files are loaded and created. Compile- and execute-functions are called.
     *
     * @param array $response the response of the student
     * @return array result
     */
    function local_execute ( $response ) {
        global $CFG;
        global $USER;
        $cfg_plugin = get_config ( 'qtype_javaunittest' );

        // create a unique temp folder to keep the data together in one place
        $temp_folder = $CFG->dataroot . '/temp/javaunittest/uid=' . $USER->id . '_qid=' . $this->question->id . '_aid=' . $this->question->questionattemptid;

        try {
            if ( file_exists ( $temp_folder ) ) {
                $this->delTree ( $temp_folder );
            }
            $this->mkdir_recursive ( $temp_folder );

            // write testfile
            if ( !preg_match ( '/^[a-zA-Z0-9_]+$/', $this->question->testclassname ) )
                throw new Exception ( 'qtype_javaunittest: testclassname contains not allowed characters' );
            $testfile = $temp_folder . '/' . $this->question->testclassname . '.java';
            $fd_testfile = fopen ( $testfile, 'w' );
            if ( $fd_testfile === false )
                throw new Exception ( 'qtype_javaunittest: could not create testfile' );
            fwrite ( $fd_testfile, $this->question->junitcode );
            fclose ( $fd_testfile );

            // try to get the name of the student's class
            $studentscode = $response['answer'];
            $matches = array ();
            preg_match ( '/^\s*public\s+class\s+(\w[a-zA-Z0-9_]+)/m', $studentscode, $matches );
            if (!empty ( $matches[1] ) && $matches[1] != $this->question->testclassname) {
                $studentsclassname =$matches[1];
            } else {
                preg_match ( '/^\s*class\s+(\w[a-zA-Z0-9_]+)/m', $studentscode, $matches );
                $studentsclassname = (!empty ( $matches[1] ) && $matches[1] != $this->question->testclassname) ? $matches[1] : 'Studentclass';
            }

            // write student's file
            $studentsfile = $temp_folder . '/' . $studentsclassname . '.java';
            $fd_studentsfile = fopen ( $studentsfile, 'w' );
            if ( $fd_studentsfile === false )
                throw new Exception ( 'qtype_javaunittest: could not create studentsfile' );
            fwrite ( $fd_studentsfile, $studentscode );
            fclose ( $fd_studentsfile );

            // compile student's response
            $compiler = $this->compile ( $studentsfile );
            $compiletime = $compiler['time'];

            // compiler error
            if ( !empty ( $compiler['compileroutput'] ) ) {
                $compileroutput = str_replace ( $temp_folder, '', $compiler['compileroutput'] );
                if ( $cfg_plugin->debug_logfile ) {
                    $logfile = $studentsfile . "_compilerout.txt";
                    $fd_logfile = fopen ( $logfile, 'w' );
                    if ( $fd_logfile === false )
                        throw new Exception ( 'qtype_javaunittest: could not create logfile' );
                    fwrite ( $fd_logfile, $compiler['compileroutput'] );
                    fclose ( $fd_logfile );
                }
                if ( !$cfg_plugin->debug_nocleanup )
                    $this->delTree ( $temp_folder );
                return array (
                        'error' => true,
                        'errortype' => 'COMPILE_STUDENT_ERROR',
                        'compileroutput' => $compileroutput
                );
            }

            // check signature
            if ( !empty ( $this->question->signature ) && trim ( $this->question->signature) != "" ) {

                // get expected signature, split by class ($toverify[2][]), impl/extends (toverify[3][]), classbody ($toverify[4][]), classbody lines ($toverify[5][][])
                $toverify = array();
                preg_match_all ( '/(public class|class) ([a-zA-Z\d_$<>]*) (.*){(.*)^}/sUm', $this->question->signature, $toverify);
                $toverify[5] = array();
                for ( $i = 0; $i < count ( $toverify[0] ); $i++ ) {
                    $toverify[2][$i] = trim ( $toverify[2][$i] );
                    $toverify[3][$i] = trim ( $toverify[3][$i] );
                    $toverify[4][$i] = trim ( $toverify[4][$i] );
                    $toverify[4][$i] = substr($toverify[4][$i], 0, -1); // remove last ;
                    $toverify[5][$i] = array();
                    $toverify[5][$i] = explode ( ";", $toverify[4][$i] );
                    for ( $a = 0; $a < count ( $toverify[5][$i] ); $a++ ) {
                        $toverify[5][$i][$a] = str_replace ( 'java.lang.', '', trim ( $toverify[5][$i][$a] ) );
                    }
                }

                // run javap
                $output = '';
                $time = 0;
                $command = $cfg_plugin->pathjavap . ' -p -constants -classpath ' . $temp_folder . ' ' . $temp_folder;
                $command = escapeshellcmd ( $command ) . '/*.class';
                $ret = open_process ( $cfg_plugin->precommand . '; exec ' . $command, $cfg_plugin->timeoutreal, $cfg_plugin->memory_limit_output * 1024, $output, $time );
                if ( $ret != OPEN_PROCESS_SUCCESS && empty ( $output ) || strstr ( $output, 'Compiled from' ) === FALSE ) {
                    throw new Exception ( 'qtype_javaunittest: signature verification failed, javap process is broken' );
                }

                // get students signature, split by class ($toverify[2][]), impl/extends (toverify[3][]), classbody ($toverify[4][]), classbody per line ($toverify[5][][])
                $javap = array();
                preg_match_all ( '/(public class|class) ([a-zA-Z\d_$<>]*) (.*){(.*)^}/sUm', $output, $javap);
                $javap[5] = array();
                for ( $i = 0; $i < count ( $javap[0] ); $i++ ) {
                    $javap[2][$i] = trim ( $javap[2][$i] );
                    $javap[3][$i] = trim ( $javap[3][$i] );
                    $javap[4][$i] = trim ( $javap[4][$i] );
                    $javap[4][$i] = substr($javap[4][$i], 0, -1); // remove last ;
                    $javap[5][$i] = array();
                    $javap[5][$i] = explode ( ";", $javap[4][$i] );
                    for ( $a = 0; $a < count ( $javap[5][$i] ); $a++ ) {
                        $javap[5][$i][$a] = str_replace ( 'java.lang.', '', trim ( $javap[5][$i][$a] ) );
                    }
                }

                // search for missing classes and elements
                $missing_classes = array();
                $missing_classes_extras = array();
                $missing_members_class = array();
                $missing_members_element = array();
                $missing_methods_class = array();
                $missing_methods_element = array();
                for ( $toverify_classindex = 0; $toverify_classindex < count ( $toverify[2] ); $toverify_classindex++ ) {
                    $found_class = FALSE;
                    for ( $javap_classindex = 0; $javap_classindex < count ( $javap[2] ); $javap_classindex++ ) {
                        if ( strcmp ( $toverify[2][$toverify_classindex], $javap[2][$javap_classindex] ) === 0 ) {
                            if ( strcmp ( $toverify[3][$toverify_classindex], $javap[3][$javap_classindex] ) === 0 ) {
                                $found_class = TRUE;

                                for ( $toverify_elemindex = 0; $toverify_elemindex < count ( $toverify[5][$toverify_classindex] ); $toverify_elemindex++ ) {
                                    $found_elem = FALSE;
                                    for ( $javap_elemindex = 0; $javap_elemindex < count ( $javap[5][$javap_classindex] ); $javap_elemindex++ ) {
                                        if ( strcmp ( $toverify[5][$toverify_classindex][$toverify_elemindex], $javap[5][$javap_classindex][$javap_elemindex] ) === 0 ) {
                                            $found_elem = TRUE;
                                        }
                                    }
                                    if ( $found_elem !== TRUE ) {
                                        if ( strstr ( $toverify[5][$toverify_classindex][$toverify_elemindex], "(" ) === FALSE ) {
                                            $missing_members_class[] = $toverify[2][$toverify_classindex];
                                            $missing_members_element[] = str_replace ( 'java.lang.', '', $toverify[5][$toverify_classindex][$toverify_elemindex] );
                                        } else {
                                            $missing_methods_class[] = $toverify[2][$toverify_classindex];
                                            $missing_methods_element[] = str_replace ( 'java.lang.', '', $toverify[5][$toverify_classindex][$toverify_elemindex] );
                                        }
                                    }
                                }

                            }
                        }

                    }
                    if ( $found_class !== TRUE ) {
                        $missing_classes[] = $toverify[2][$toverify_classindex];
                        $missing_classes_extras[] = $toverify[3][$toverify_classindex];
                    }
                }

                if ( !empty ( $missing_classes ) || !empty ( $missing_members_class ) || !empty ( $missing_methods_class ) ) {
                    return array (
                            'error' => true,
                            'errortype' => 'SIGNATURE_STUDENT_MISSMATCH',
                            'missing_classes' => $missing_classes,
                            'missing_classes_extras' => $missing_classes_extras,
                            'missing_members_class' => $missing_members_class,
                            'missing_members_element' => $missing_members_element,
                            'missing_methods_class' => $missing_methods_class,
                            'missing_methods_element' => $missing_methods_element
                    );
                }
            }

            // compile testfile
            $compiler = $this->compile ( $testfile );
            $compiletime += $compiler['time'];

            // compiler error
            if ( !empty ( $compiler['compileroutput'] ) ) {
                $compileroutput = str_replace ( $temp_folder, '', $compiler['compileroutput'] );
                if ( $cfg_plugin->debug_logfile ) {
                    $logfile = $testfile . "_compilerout.txt";
                    $fd_logfile = fopen ( $logfile, 'w' );
                    if ( $fd_logfile === false )
                        throw new Exception ( 'qtype_javaunittest: could not create logfile' );
                    fwrite ( $fd_logfile, $compiler['compileroutput'] );
                    fclose ( $fd_logfile );
                }
                if ( !$cfg_plugin->debug_nocleanup )
                    $this->delTree ( $temp_folder );
                return array (
                        'error' => true,
                        'errortype' => 'COMPILE_TESTFILE_ERROR',
                        'compileroutput' => $compileroutput
                );
            }

            // run test
            $command = $cfg_plugin->pathjava . ' -Xmx' . $cfg_plugin->memory_xmx .
                     'm -Djava.security.manager=default -Djava.security.policy=' . $cfg_plugin->pathpolicy . ' -cp ' .
                     $cfg_plugin->pathjunit . ':' . $cfg_plugin->pathhamcrest . ':' . $temp_folder .
                     ' org.junit.runner.JUnitCore ' . $this->question->testclassname;

            $output = '';
            $testruntime = 0;

            $ret_proc = open_process ( $cfg_plugin->precommand . '; exec ' . escapeshellcmd ( $command ),
                    $cfg_plugin->timeoutreal, $cfg_plugin->memory_limit_output * 1024, $output, $testruntime );

            if ( $cfg_plugin->debug_logfile ) {
                $logfile = $testfile . "_junitout.txt";
                $fd_logfile = fopen ( $logfile, 'w' );
                if ( $fd_logfile === false )
                    throw new Exception ( 'qtype_javaunittest: could not create logfile' );
                fwrite ( $fd_logfile, $output );
                fclose ( $fd_logfile );
            }
            if ( !$cfg_plugin->debug_nocleanup )
                $this->delTree ( $temp_folder );

            if ( $ret_proc == OPEN_PROCESS_TIMEOUT || $ret_proc == OPEN_PROCESS_UNCAUGHT_SIGNAL ) {
                return array (
                        'error' => true,
                        'errortype' => 'TIMEOUT_RUNNING'
                );
            }

            return array (
                    'junitoutput' => $output,
                    'error' => false,
                    'compiletime' => $compiletime,
                    'testruntime' => $testruntime
            );
        } catch ( Exception $e ) {
            if ( !$cfg_plugin->debug_nocleanup )
                $this->delTree ( $temp_folder );
            throw $e;
        }
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
     * Assistent function to compile the java code
     *
     * @param string $file the .java file that should be compiled
     * @return array $compileroutput and $time
     */
    function compile ( $file ) {
        $cfg_plugin = get_config ( 'qtype_javaunittest' );

        $command = $cfg_plugin->pathjavac . ' -encoding UTF-8 -nowarn -cp ' . $cfg_plugin->pathjunit . ' -sourcepath ' . dirname ( $file ) . ' ' . $file;

        // execute the command
        $compileroutput = '';
        $time = 0;
        $ret = open_process ( $cfg_plugin->precommand . ';' . escapeshellcmd ( $command ), $cfg_plugin->timeoutreal, $cfg_plugin->memory_limit_output * 1024, $compileroutput, $time );

        if ( $ret != OPEN_PROCESS_SUCCESS && empty ( $compileroutput ) ) {
            $compileroutput = 'error (timeout?)';
        }

        return array (
                'compileroutput' => $compileroutput,
                'time' => $time
        );
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