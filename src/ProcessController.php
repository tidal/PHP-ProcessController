<?php
/*
 * (c) 2011 Timo Michna <timomichna/yahoo.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * ProcessController
 *
 * @author Timo Michna <timomichna/yahoo.de>
 */
class ProcessController {

    const
        WAIT_FOR_CHILD_TIMEOUT = 5000,
        EVENT_FORK          = 'fork',
        EVENT_FORK_CHILD    = 'fork_child',
        EVENT_FORK_PARENT   = 'fork_parent',
        EVENT_FORK_ERROR    = 'fork_error',
        EVENT_FORK_ROOT     = 'fork_root',
        EVENT_DAEMONIZE     = 'daemonize',
        EVENT_STOP_CHILDS   = 'stop_childs',
        EVENT_KILL          = 'kill',
        EVENT_KILLED_CHILD  = 'killed_child';


    static protected
        $depth = 0,
        $pid = NULL,
        $sid = NULL,
        $termCount = 0,
        $waitForSignals = false,
        $isParent = true,
        $isChild = false,
        $children = array(),
        $parentPid = NULL,
        $helperPid = NULL,
        $forkPid = NULL,
        $stopProcess = false,
        $stopTime = NULL,
        $callBacks = array(),
        $throwException = false;


    /**
     * Returns depth of forked processes.
     * Root-Process returns 0
     *
     * @returns int depth of current process.
     */
    public static function getDepth(){
        return self::$depth;
    }

    /**
     * Returns current Process ID
     *
     * @returns int current Process ID, or FALSE on error.
     */
	public static function getPid(){
		return self::$pid ? self::$pid : self::setMyPid();
	}


    /**
     * Wether process is parent process
     *
     * @returns bool Wether process is parent process
     */
    public static function isParent(){
        return self::$isParent;
    }

    /**
     * Wether process is child process
     *
     * @returns bool Wether process is child process
     */
    public static function isChild(){
        return self::$isChild;
    }

    /**
     * Wether process is root process (session leader)
     *
     * @returns bool Wether process is root process
     */
    public static function isRoot(){
        return self::$isParent &&  !self::$isChild;
    }


    /**
     * Sets PID to PID of current process
     */
    protected static function setMyPid(){
        self::$pid = self::getMyPid();
        if(self::$pid === false){
            self::throwPidException();
        }
    }


    /**
     * Returns current Process ID  - result of calling getmypid()
     * This method is needed to test error conditions in  setMyPid()
     *
     * @returns int current Process ID, or FALSE on error.
     */
    protected static function getMyPid(){
        return getmypid();
    }

    /**
     * makes current process the session leader
     *
     */
    protected static function setSid(){
        posix_setsid();
    }

    protected static function setMySid(){
        self::$sid = self::getMySid();
        if(self::$sid === false){
            self::throwPosixException();
        }
    }

    /**
     * Returns current Session-ID - result of calling posix_getsid() with current pid
     * This method is needed to test error conditions in  setSid()
     *
     * @returns int current Session-ID , or FALSE on error.
     */
    protected static function getMySid(){
        return posix_getsid(0);
    }

    protected static function setParentPid(){
        self::$parentPid = self::$pid;
    }

    public static function getChildPids(){
        return self::$children;
    }

    public static function getChildCount(){
        return count(self::$children);
    }

    protected static function setParent($value){
        self::$isParent = (boolean)$value;
    }

    protected static function setDepth($value){
        self::$depth = (int)$value;
    }

    protected static function increaseDepth(){
        self::$depth++;
    }

    protected static function initDepth(){
        self::$depth = 0;
    }

    protected static function setChild($value){
        self::$isChild = (boolean)$value;
    }

    protected static function initChildren(){
        self::$children = array();
    }

    protected static function addChildPid($pid){
        self::$children[] = $pid;
    }

    public static function setStopProcess($value){
        self::$stopProcess = (boolean)$value;
    }

    public static function setWaitForSignals($value){
        self::$waitForSignals = (boolean)$value;
    }

    public static function setThrowExcption($value){
        self::$throwException = (boolean)$value;
    }

    public static function init($parent=true){

        self::setMyPid();
        self::setMySid();
        
        if($parent){
            foreach(array(
                SIGTERM,
                SIGINT,
                SIGUSR1,
                SIGUSR2,
                SIGCONT,
                SIGHUP
            ) as $signal){ 
                pcntl_signal($signal, array('ProcessController', "signal"));
            }
        } else { 
            if(!pcntl_signal(SIGTERM, array('ProcessController', "signal"))){
                exit();
            }
        }
    }


    /**
     * Forks the process and runs the given method. The parent then waits
     * for the child process to signal back that it can continue
     *
     * @param   string  $method  Class method to run after forking
     *
     */
    public static function fork($callback = NULL){
        self::setWaitForSignals(true);
        self::doFork();
        switch(self::$forkPid) {
            case 0:
                // child
                self::setParent(false);
                self::setChild(true);
                self::setParentPid();
                self::setMyPid();
                self::increaseDepth();
                self::initChildren();
                self::runCallBack(self::EVENT_FORK_CHILD);
                break;
            case -1:
                // error
                self::setStopProcess(true);
                self::stopChildren();
                self::runCallBack(self::EVENT_FORK_ERROR);
                self::throwPCNTLException('Could not fork');
                break;
            default:
                // parent
                self::setParent(true);
                self::addChildPid(self::$forkPid);
                self::runCallBack(self::EVENT_FORK_PARENT);
                if(self::isRoot()){
                    self::runCallBack(self::EVENT_FORK_ROOT);
                }
                break;
        }
        self::runCallBack(self::EVENT_FORK);
        self::executeCallback($callback);

        return self::$forkPid;
    }

    public static function detatch(){      
        self::setSid();
        self::setMySid();
    }

    public static function daemonize(){
        $forkPid = self::fork();
        if($forkPid === 0){
            self::detatch();
            self::runCallBack(self::EVENT_DAEMONIZE);
        }elseif($forkPid > 0) {
            self::stop();
        }
        
    }

    protected static function doFork(){
        return self::$forkPid = pcntl_fork();
    }

    public static function waitForChild($pid){
        if(self::$isparent){
            while(self::$waitForSignals && !self::$stopProcess) {
                usleep(self::WAIT_FOR_CHILD_TIMEOUT);
                pcntl_waitpid($pid, $status, WNOHANG);
                if (pcntl_wifexited($status) && $status) {
                     exit(1);
                }
            }
        }
    }

    public static function waitForFork(){
        return self::waitForChild(self::fork());
    }


    /**
     * Handles signals
     */
    public static function signal($signo) {

        self::$termCount = 0;

        if(!self::$isParent){

            self::$stopProcess = true;

        } else {

            switch ($signo) {
                case SIGUSR1:
                    break;
                case SIGUSR2:
                    break;
                case SIGCONT:
                    self::$waitForSignals = false;
                    break;
                case SIGINT:
                case SIGTERM:
                    self::$stopProcess = true;
                    self::$stopTime = time();
                    self::$termCount++;
                    if(self::$termCount < 5){
                        self::stopChildren();
                    } else {
                        self::stopChildren(SIGKILL);
                    }
                    break;
                case SIGHUP:
                    self::stopChildren();
                    break;
                default:
                // handle all other signals
            }
        }

        if($signo === SIGTERM){
            self::runCallBack(self::EVENT_KILL);
        }

    }

    public static function stop($error = 0){
        exit($error);
    }


    /**
     * Stops all running children
     */
    public static function stopChildren($signal=SIGTERM) { 
        self::runCallBack(self::EVENT_STOP_CHILDS);
        foreach(self::$children as $pid){
            $res = posix_kill($pid, $signal);
            if($res=== false){
                self::throwPosixException();
            }else{
                self::runCallBack(self::EVENT_KILLED_CHILD);
            }
        }
    }


    public static function registerCallBack($event, $callback = NULL){
        self::$callBacks[$event] = is_callable($callback)
            ? $callback
            : NULL;
    }

    protected  static function runCallBack($event){
        if(isset(self::$callBacks[$event])){
            return self::executeCallback(self::$callBacks[$event]);
        }
    }

    protected static function executeCallback($callback){
        if($callback && is_callable($callback)){
            @call_user_func($callback, self::getPid());
        }
    }

    protected static function throwPosixException(){
        if(self::hadPosixError() && self::$throwException){
            throw new ProcessControllerPosixException();
        }
    }

    protected function throwPidException(){
        return self::throwRuntimeException('Could not receive current process ID');
    }

    protected static function throwPCNTLException($message, $code = false, $previous = false){
        if(self::$throwException){
            throw new ProcessControllerPCNTLException($message, $code, $previous);
        }
    }


    protected static function throwRuntimeException($message, $code = false, $previous = false){
        if(self::$throwException){
            throw new RuntimeException($message, $code, $previous);
        }
    }

    protected function hadPosixError(){
        return posix_get_last_error() > 0;
    }

}

ProcessController::init();





class ProcessControllerPosixException extends RuntimeException {

    public function  __construct() {
        $errNr = posix_get_last_error();
        parent::__construct(posix_strerror($errNr), $errNr);
    }

}

class ProcessControllerPCNTLException extends RuntimeException {

    public function  __construct($message, $code = false, $previous = false) {
        parent::__construct('PCNTL ERROR: '.$message, $code, $previous);
    }

}
