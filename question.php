<?php

/**
 * The question class for this question type.
 *
 * @package    qtype
 * @subpackage javaunittest
 * @author     Gergely Bertalan, bertalangeri@freemail.hu
 * @author     Michael Rumler, rumler@ni.tu-berlin.de
 * @author     Martin Gauk, gauk@math.tu-berlin.de
 * @reference  sojunit 2008, Süreç Özcan, suerec@darkjade.net
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 OR later
 */
defined ( 'MOODLE_INTERNAL' ) || die ();
require_once ( dirname ( __FILE__ ) . '/lib.php' );
require_once (dirname(__FILE__) . '/executors/executor.php');
require_once (dirname(__FILE__) . '/executors/junit4.php');

/**
 * Represents a javaunittest question.
 */
class qtype_javaunittest_question extends question_graded_automatically {
    public $responseformat = 'plain';
    public $responsefieldlines;
    public $givencode;
    public $testclassname;
    public $junitcode;
    public $solution;
    public $signature;
    public $feedbacklevel_studentcompiler;
    public $feedbacklevel_studentsignature;
    public $feedbacklevel_junitcompiler;
    public $feedbacklevel_times;
    public $feedbacklevel_counttests;
    public $feedbacklevel_junitheader;
    public $feedbacklevel_assertstring;
    public $feedbacklevel_assertexpected;
    public $feedbacklevel_assertactual;
    public $feedbacklevel_junitcomplete;
    public $questionattemptid = null;
    
    public function __construct () {
        parent::__construct ();

        // load CSS here since on pages generated with usage of ./renderer.php 
        // the output starts before ./lib.qtype_javaunittest_generateJsBy is called
        qtype_javaunittest_require_css ();
    }
    
    /**
     * The moodle_page the page we are outputting to.
     *
     * @param moodle_page $page
     * @return qtype_javaunittest_format_renderer_base the response-format-specific renderer
     */
    public function get_format_renderer ( moodle_page $page ) {
        return $page->get_renderer ( 'qtype_javaunittest', 'format_' . $this->responseformat );
    }
    
    /**
     * The methode is called when the question attempt is actually stared and does necessary initialisation. In this
     * case only the type of the answer is defined.
     *
     * @return array of expected parameters
     */
    public function get_expected_data () {
        return array (
                'answer' => PARAM_RAW_TRIMMED 
        );
    }
    
    /**
     * Sumarize the response of the student.
     *
     * @param array $response
     * @return string answer OR null
     */
    public function summarise_response ( array $response ) {
        if ( isset ( $response['answer'] ) ) {
            $formatoptions = new stdClass ();
            $formatoptions->para = false;
            return html_to_text ( format_text ( $response['answer'], FORMAT_HTML, $formatoptions ), 0, false );
        } else {
            return null;
        }
    }
    
    /**
     * Delivers the sample solution
     *
     * @return array
     */
    public function get_correct_response () {
        $response = array();
        $response['answer'] = $this->solution;
        return $response;
    }
    
    /**
     * Check whether the student has already answered the question.
     *
     * @param array $response
     * @return bool true if $response['answer'] is not empty
     */
    public function is_complete_response ( array $response ) {
        return !empty ( $response['answer'] );
    }
    
    /**
     * Validate the student's response. Since we have a gradable response, we always return an empty string here.
     *
     * @param array $response
     * @return string empty string OR please-select-an-answer-message
     */
    public function get_validation_error ( array $response ) {
        return '';
    }
    
    /**
     * Every time students change their response in the texteditor this function is called to check whether the
     * student's newly entered response differs.
     *
     * @param array $newresponse
     * @return boolean true if old and new response->answer are equal
     */
    public function is_same_response ( array $prevresponse, array $newresponse ) {
        return question_utils::arrays_same_at_key_missing_is_blank ( $prevresponse, $newresponse, 'answer' );
    }
    
    /**
     * When an in-progress {@link question_attempt} is re-loaded from the database, this method is called so that the
     * question can re-initialise its internal state as needed by this attempt. For example, the multiple choice
     * question type needs to set the order of the choices to the order that was set up when start_attempt was called
     * originally. All the information required to do this should be in the $step object, which is the first step of the
     * question_attempt being loaded.
     *
     * @param question_attempt_step The first step of the {@link question_attempt} being loaded.
     */
    public function apply_attempt_state ( question_attempt_step $step ) {
        global $DB;
        
        $stepid = $step->get_id ();
        
        // We need a place to store the feedback generated by JUnit.
        // Therefore we want to know the questionattemptid.
        if ( !empty ( $stepid ) ) {
            $record = $DB->get_record ( 'question_attempt_steps', array (
                    'id' => $stepid 
            ), 'questionattemptid' );
            if ( $record ) {
                $this->questionattemptid = $record->questionattemptid;
            }
        }
    }
    
    /**
     * Call local or remote execute function. Evaluation of junit output is done. Grade is calculated and feedback
     * generated.
     *
     * @param array $response the response of the student
     * @return array $fraction fraction of the grade. If the max grade is 10 then fraction can be for example 2 (10/5 =
     *         2 indicating that from 10 points the student achieved 5).
     */
    public function grade_response ( array $response ) {
        global $CFG, $DB;
        
        if ( $this->questionattemptid === null ) {
            throw new Exception ( 'qtype_javaunittest: grade_response(), no questionattemptid' );
        }
        
        $fraction = 0;
        $feedback = '';
        $cfg_plugin = get_config ( 'qtype_javaunittest' );

        $executor = new qtype_javaunittest_question_junit4_executor($this);
        
        if ( empty ( $cfg_plugin->remoteserver ) ) {
            $ret = $executor->local_execute ( $response );
        } else {
            $ret = $executor->remote_execute ( $response );
        }

        if ( $ret['error'] ) {
            if ( $ret['errortype'] == 'COMPILE_STUDENT_ERROR' ) {
                $feedback = get_string ( 'CE', 'qtype_javaunittest' ) . '<br><br>';
                if ( $this->feedbacklevel_studentcompiler == 1 ) {
                    $feedback .= '<pre>' . htmlspecialchars ( $ret['compileroutput'] ) . '</pre>';
                }
            } else if ( $ret['errortype'] == 'SIGNATURE_STUDENT_MISSMATCH' ) {
                $feedback = get_string ( 'SSM', 'qtype_javaunittest' ) . '<br><br>';
                if ( $this->feedbacklevel_studentsignature == 1 ) {
                    if ( count ( $ret['missing_classes'] ) > 0 ) {
                        $feedback .= get_string ( 'missing_classes_headline', 'qtype_javaunittest' ) . '<br>';
                        for ( $i = 0; $i < count ( $ret['missing_classes'] ); $i++ ) {
                            $feedback .= get_string ( 'missing_classes_text1', 'qtype_javaunittest', htmlspecialchars ( $ret['missing_classes'][$i] ) );
                            $feedback .= get_string ( 'missing_classes_text2', 'qtype_javaunittest', htmlspecialchars ( $ret['missing_classes_extras'][$i] ) ) . '<br>';
                        }
                        $feedback .= '<br>';
                    }
                    if ( count ( $ret['missing_members_class'] ) > 0 ) {
                        $feedback .= get_string ( 'missing_members_headline', 'qtype_javaunittest' ) . '<br>';
                        for ( $i = 0; $i < count ( $ret['missing_members_class'] ); $i++ ) {
                            $feedback .= get_string ( 'missing_members_text1', 'qtype_javaunittest', htmlspecialchars ( $ret['missing_members_class'][$i] ) );
                            $feedback .= get_string ( 'missing_members_text2', 'qtype_javaunittest', htmlspecialchars ( $ret['missing_members_element'][$i] ) ) . '<br>';
                        }
                        $feedback .= '<br>';
                    }
                    if ( count ( $ret['missing_methods_class'] ) > 0 ) {
                        $feedback .= get_string ( 'missing_methods_headline', 'qtype_javaunittest' ) . '<br>';
                        for ( $i = 0; $i < count ( $ret['missing_methods_class'] ); $i++ ) {
                            $feedback .= get_string ( 'missing_methods_text1', 'qtype_javaunittest', htmlspecialchars ( $ret['missing_methods_class'][$i] ) );
                            $feedback .= get_string ( 'missing_methods_text2', 'qtype_javaunittest', htmlspecialchars ( $ret['missing_methods_element'][$i] ) ) . '<br>';
                        }
                        $feedback .= '<br>';
                    }
                }
            } else if ( $ret['errortype'] == 'COMPILE_TESTFILE_ERROR' ) {
                $feedback = get_string ( 'JE', 'qtype_javaunittest' ) . '<br><br>';
                if ( $this->feedbacklevel_junitcompiler == 1 ) {
                    $feedback .= '<pre>' . htmlspecialchars ( $ret['compileroutput'] ) . '</pre>'; 
                }
            } else if ( $ret['errortype'] == 'TIMEOUT_RUNNING' ) {
                $feedback = get_string ( 'TO', 'qtype_javaunittest' ) . '<br><br>';
            } else if ( $ret['errortype'] == 'REMOTE_SERVER_ERROR' ) {
                $feedback = get_string ( 'RSE', 'qtype_javaunittest' ) . '<br><br>';
                $feedback .= '<pre>' . htmlspecialchars ( $ret['message'] ) . '</pre>';
            }
        } else {
            
            // the JUnit-execution-output returns always a String in the first line
            // e.g. "...F",
            // which means that 1 out of 3 test cases didn't pass the JUnit test
            // In the second line it says "Time ..."
            $output = $ret['junitoutput'];
            $junitstart = strrpos ( $output, 'JUnit version' );
            $matches = array ();
            $found = preg_match ( '@JUnit version [\d\.]*\n([\.EF]+)\n@', $output, $matches, 0, $junitstart );
            
            if ( !$found ) {
                $feedback = get_string ( 'JE', 'qtype_javaunittest' );
            } else {
                // count failures and errors
                $numtests = substr_count ( $matches[1], '.' );
                $numfailures = substr_count ( $matches[1], 'F' );
                $numerrors = substr_count ( $matches[1], 'E' );
                $totalerrors = $numfailures + $numerrors;
                
                // generate fraction
                $fraction = 1 - round ( ($totalerrors / $numtests), 2 );
                
                // add feedback depending on feedbacklevel
                if ( $this->feedbacklevel_times == 1 ) {
                    $feedback .= get_string ( 'compiling', 'qtype_javaunittest', round ( $ret['compiletime'], 1 ) ) . "<br>\n";
                    $feedback .= get_string ( 'running', 'qtype_javaunittest', round ( $ret['testruntime'], 1 ) ) . "<br>\n<br>\n";
                }
                if ( $this->feedbacklevel_counttests == 1 ) {
                    $feedback .= "Tests: " . $numtests . "<br>\n";
                    $feedback .= "Failures: " . $numfailures . "<br>\n";
                    $feedback .= "Errors: " . $numerrors . "<br>\n<br>\n";
                }
                if ( $this->feedbacklevel_junitheader == 1 ) {
                    $matches = array ();
                    $found = preg_match ( '@(.*)There (were|was) (\d*) failure(s?):\n@s', $output, $matches );
                    if ( $found ) 
                        $feedback .= "<pre>" . htmlspecialchars ( $matches[1] ) . "</pre><br>\n";
                    else
                        $feedback .= "<pre>" . htmlspecialchars ( $output ) . "</pre><br>\n";
                }
                if ( $this->feedbacklevel_assertstring == 1) {
                    $matches = array();
                    $hiddematches = array();
                    $found = preg_match_all ( '/(java\.lang\.AssertionError|org\.junit\.ComparisonFailure|junit\.framework\.AssertionFailedError): ([^<>]*)(expected:<(.*)> but was:<(.*)>|$)/mUs', $output, $matches );
                    $hidden = preg_match_all ( '/(java\.lang\.AssertionError|org\.junit\.ComparisonFailure|junit\.framework\.AssertionFailedError)$/mUs', $output, $hiddenmatches );
                    foreach ( $matches[2] as $asserstr ) 
                        if ( trim ( $asserstr ) == "Hide" )
                            $hidden++;
                    if ( ( $found && $hidden === FALSE ) || ( $found && $found > $hidden - $found ) ) {
                        $feedback .= '<table class="feedback_assert_table"><thead><tr>';
                        $feedback .= '<th class="feedback_assert_th_str">' . get_string ( 'assertfailures_string', 'qtype_javaunittest' ) . '</th>';
                        if ( $this->feedbacklevel_assertexpected == 1 )
                            $feedback .= '<th class="feedback_assert_th_val">' . get_string ( 'assertfailures_expected', 'qtype_javaunittest' ) . '</th>';
                        if ( $this->feedbacklevel_assertactual == 1 )
                            $feedback .= '<th class="feedback_assert_th_val">' . get_string ( 'assertfailures_actual', 'qtype_javaunittest' ) . '</th>';
                        $feedback .= '</thead></tr><tbody>';
                        for ( $c = 0; $c < $found; $c++ ) {
                            if ( trim ( $matches[2][$c] ) == "Hide" ) 
                                continue;
                            $feedback .= '<tr>';
                            $feedback .= '<td class="feedback_assert_td_str">' . htmlspecialchars ( $matches[2][$c] ) . '</td>';
                            if ( $this->feedbacklevel_assertexpected == 1 ) {
                                $find = array('{', '}', '[', ']', '(', ')', '<', '>');
                                $matches[3][$c] = str_replace ( $find, '', $matches[3][$c] );
                                $feedback .= '<td class="feedback_assert_td_val">' . htmlspecialchars ( $matches[4][$c] ) . '</td>';
                            } 
                            if ( $this->feedbacklevel_assertactual == 1 ) {
                                $find = array('{', '}', '[', ']', '(', ')', '<', '>');
                                $matches[4][$c] = str_replace ( $find, '', $matches[4][$c] );
                                $feedback .= '<td class="feedback_assert_td_val">' . htmlspecialchars ( $matches[5][$c] ) . '</td>';
                            }
                            $feedback .= '</tr>';
                        }
                        $feedback .= '</tbody></table>';
                        $feedback .= "<br>\n<br>\n";
                    }
                    if ( $hidden ) {
                        $feedback .= get_string ( 'hiddenfails', 'qtype_javaunittest', $hidden );
                        $feedback .= "<br>\n<br>\n";
                    }
                }
                if ( $this->feedbacklevel_junitcomplete == 1 ) {
                    $feedback .= "<pre>" . $output . "</pre><br>\n";
                }
                
                // search for common throwables, ordered primary by package, secundary by alphabet and add feedback
                if ( strpos ( $output, 'java.io.IOException' ) !== false )
                    $feedback .= get_string ( 'ioexception', 'qtype_javaunittest' ) . "<br>\n<br>\n";
                if ( strpos ( $output, 'java.io.FileNotFoundException' ) !== false )
                    $feedback .= get_string ( 'filenotfoundexception', 'qtype_javaunittest' ) . "<br>\n<br>\n";
                if ( strpos ( $output, 'java.lang.ArrayIndexOutOfBoundsException' ) !== false )
                    $feedback .= get_string ( 'arrayindexoutofboundexception', 'qtype_javaunittest' ) . "<br>\n<br>\n";
                if ( strpos ( $output, 'java.lang.ClassCastException' ) !== false )
                    $feedback .= get_string ( 'classcastexception', 'qtype_javaunittest' ) . "<br>\n<br>\n";
                if ( strpos ( $output, 'java.lang.NegativeArraySizeException' ) !== false )
                    $feedback .= get_string ( 'negativearraysizeexception', 'qtype_javaunittest' ) . "<br>\n<br>\n";
                if ( strpos ( $output, 'java.lang.NullPointerException' ) !== false )
                    $feedback .= get_string ( 'nullpointerexception', 'qtype_javaunittest' ) . "<br>\n<br>\n";
                if ( strpos ( $output, 'java.lang.OutOfMemoryError' ) !== false )
                    $feedback .= get_string ( 'outofmemoryerror', 'qtype_javaunittest' ) . "<br>\n<br>\n";
                if ( strpos ( $output, 'java.lang.StackOverflowError' ) !== false )
                    $feedback .= get_string ( 'stackoverflowerror', 'qtype_javaunittest' ) . "<br>\n<br>\n";
                if ( strpos ( $output, 'java.lang.StringIndexOutOfBoundsException' ) !== false )
                    $feedback .= get_string ( 'stringindexoutofboundexception', 'qtype_javaunittest' ) . "<br>\n<br>\n";
                if ( strpos ( $output, 'java.nio.BufferOverflowException' ) !== false )
                    $feedback .= get_string ( 'bufferoverflowexception', 'qtype_javaunittest' ) . "<br>\n<br>\n";
                if ( strpos ( $output, 'java.nio.BufferUnderflowException' ) !== false )
                    $feedback .= get_string ( 'bufferunderflowexception', 'qtype_javaunittest' ) . "<br>\n<br>\n";
                if ( strpos ( $output, 'java.security.AccessControlException' ) !== false )
                    $feedback .= get_string ( 'accesscontrolexception', 'qtype_javaunittest' ) . "<br>\n<br>\n";
                
                // append feedback phrase (wrong / [partially] corrent answer phrase)
                if ( $numtests > 0 && $totalerrors == 0 )
                    $feedback .= get_string ( 'CA', 'qtype_javaunittest' ) . "<br>\n";
                else if ( $numtests > 0 && $numtests == $totalerrors )
                    $feedback .= get_string ( 'WA', 'qtype_javaunittest' ) . "<br>\n";
                else if ( $numtests > 0 && $totalerrors != 0 )
                    $feedback .= get_string ( 'PCA', 'qtype_javaunittest' ) . "<br>\n";
                
            }
        }
        
        // save feedback
        $cur_feedback = $DB->get_record ( 'qtype_javaunittest_feedback', array (
                'questionattemptid' => $this->questionattemptid 
        ) );
        
        $db_feedback = new stdClass ();
        $db_feedback->questionattemptid = $this->questionattemptid;
        $db_feedback->feedback = $feedback;
        if ( $cur_feedback ) {
            $db_feedback->id = $cur_feedback->id;
            $DB->update_record ( 'qtype_javaunittest_feedback', $db_feedback );
        } else {
            $DB->insert_record ( 'qtype_javaunittest_feedback', $db_feedback );
        }
        
        return array (
                $fraction,
                question_state::graded_state_for_fraction ( $fraction ) 
        );
    }
}


define ( 'OPEN_PROCESS_SUCCESS', 0 );
define ( 'OPEN_PROCESS_TIMEOUT', 1 );
define ( 'OPEN_PROCESS_OUTPUT_LIMIT', 2 );
define ( 'OPEN_PROCESS_UNCAUGHT_SIGNAL', 3 );
define ( 'OPEN_PROCESS_OTHER_ERROR', 4 );

/**
 * Execute a command on shell and return all outputs
 *
 * @param string $cmd command on shell
 * @param int $timeout_real timeout in secs (real time)
 * @param int $output_limit stops the process if the output on stdout/stderr reaches a limit (in Bytes)
 * @param string &$output stdout/stderr of process
 * @param float &$time time needed for execution (in s)
 * @return int OPEN_PROCESS_SUCCESS, OPEN_PROCESS_TIMEOUT, OPEN_PROCESS_OUTPUT_LIMIT or OPEN_PROCESS_OTHER_ERROR
 */
function open_process ( $cmd, $timeout_real, $output_limit, &$output, &$time ) {
    $descriptorspec = array (
            0 => array (
                    "pipe",
                    "r" 
            ), // stdin
            1 => array (
                    "pipe",
                    "w" 
            ), // stdout
            2 => array (
                    "pipe",
                    "w" 
            ) 
    ); // stderr
    
    $process = proc_open ( $cmd, $descriptorspec, $pipes );
    
    if ( !is_resource ( $process ) ) {
        return OPEN_PROCESS_OTHER_ERROR;
    }
    
    // pipes should be non-blocking
    stream_set_blocking ( $pipes[1], 0 );
    stream_set_blocking ( $pipes[2], 1 );
    
    $orig_pipes = array (
            $pipes[1],
            $pipes[2] 
    );
    $starttime = microtime ( true );
    $stderr_content = '';
    $ret = -1;
    
    while ( $ret < 0 ) {
        $r = $orig_pipes;
        $write = $except = null;
        
        if ( count ( $r ) ) {
            $num_changed = stream_select ( $r, $write, $except, 0, 800000 );
            if ( $num_changed === false ) {
                continue;
            }
        } else {
            usleep ( 800000 );
        }
        
        foreach ( $r as $stream ) {
            if ( feof ( $stream ) ) {
                $key = array_search ( $stream, $orig_pipes, true );
                unset ( $orig_pipes[$key] );
            } else if ( $stream === $pipes[1] ) {
                $output .= stream_get_contents ( $stream );
            } else if ( $stream === $pipes[2] ) {
                $stderr_content .= stream_get_contents ( $stream );
            }
        }
        
        $status = proc_get_status ( $process );
        
        // check time
        $time = microtime ( true ) - $starttime;
        if ( $time >= $timeout_real ) {
            proc_terminate ( $process, defined ( 'SIGKILL' ) ? SIGKILL : 9 );
            $ret = OPEN_PROCESS_TIMEOUT;
        }
        
        // check output limit
        if ( (strlen ( $output ) + strlen ( $stderr_content )) > $output_limit ) {
            proc_terminate ( $process, defined ( 'SIGKILL' ) ? SIGKILL : 9 );
            $ret = OPEN_PROCESS_OUTPUT_LIMIT;
        }
        
        if ( $status['signaled'] ) {
            $ret = OPEN_PROCESS_UNCAUGHT_SIGNAL;
        } else if ( !$status['running'] ) {
            $ret = OPEN_PROCESS_SUCCESS;
        }
    }
    
    $output .= $stderr_content;
    
    // all pipes need to be closed before calling proc_close
    fclose ( $pipes[0] );
    fclose ( $pipes[1] );
    fclose ( $pipes[2] );
    
    proc_close ( $process );
    
    $time = microtime ( true ) - $starttime;
    
    return $ret;
}
