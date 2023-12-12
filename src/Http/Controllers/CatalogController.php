<?php
/**
 * ProtocolController.php
 * Date: 16.05.2017
 * Time: 16:09
 * Author: Maksim Klimenko
 * Email: mavsan@gmail.com
 */

namespace Mavsan\LaProtocol\Http\Controllers;

use Auth;
use Exception;
use File;
use Gate;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Log;
use Mavsan\LaProtocol\Http\Controllers\Traits\ImportsCatalog;
use Mavsan\LaProtocol\Http\Controllers\Traits\SharesSale;
use Mavsan\LaProtocol\Model\FileName;
use Session;

class CatalogController extends BaseController
{
    /** @var  Request */
    protected $request;
    protected $stepCheckAuth = 'checkauth';
    protected $stepInit = 'init';

    protected $stepFile = 'file';
    protected $stepImport = 'import';
    protected $stepDeactivate = 'deactivate';
    protected $stepComplete = 'complete';

    protected $stepQuery = 'query';
    protected $stepSuccess = 'success';

    use SharesSale;
    use ImportsCatalog;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    protected function defaultType()
    {
        return config('protocolExchange1C.defaultType');
    }

    /**
     * Запись в лог данных запроса, если это необходимо
     *
     * @param $type
     * @param $mode
     */
    protected function logRequestData($type, $mode)
    {
        if (config('protocolExchange1C.logCommandsOf1C', false)) {
            Log::debug('Command from 1C type: '.$type.'; mode: '.$mode);
        }

        if (config('protocolExchange1C.logCommandsHeaders', false)) {
            Log::debug('Headers:');
            Log::debug($this->request->header());
        }

        if (config('protocolExchange1C.logCommandsFullUrl', false)) {
            Log::debug('Request: '.$this->request->fullUrl());
        }
    }

    public function catalogIn()
    {
        $type = $this->request->get('type');
        $mode = $this->request->get('mode');

        if (! $type) {
            $type = $this->defaultType();
        }

        $this->logRequestData($type, $mode);

        if ($type != 'catalog' && $type != 'sale') {
            return $this->checkAuth('');
        }

        if (! $this->checkCSRF($mode)) {
            return $this->failure('CSRF token mismatch');
        }

        if (! $this->userLogin()) {
            return $this->failure('wrong username or password');
        } else {
            // как выяснилось - после авторизации Laravel меняет id сессии, т.о.
            // при каждом запросе от 1С будет новая сессия и если что-то туда
            // записать то это будет потеряно, поэтому берем ИД сессии, который
            // был отправлен в 1С на этапе авторизации и принудительно устанавливаем
            $cookie = $this->request->header('cookie');
            $sessionName = config('session.cookie');
            if ($cookie
                && preg_match("/$sessionName=([^;\s]+)/", $cookie, $matches)) {
                // если убрать эту строчку и сделать вот так
                // session()->setId($matches[1]), то ИНОГДА o_O это приводит к
                // ошибке - говорит, что ничего не передано, хотя оно есть и
                // передается
                $id = $matches[1];
                session()->setId($id);
            }
        }

        switch ($mode) {
            case $this->stepCheckAuth:
                return $this->checkAuth($type);
                break;

            case $this->stepInit:
                return $this->init($type);
                break;

            case $this->stepFile:
                return $this->getFile();
                break;

            case $this->stepImport:
                try {
                    return $this->import();
                } catch (Exception $e) {
                    return $this->failure($e->getMessage());
                }
                break;

            case $this->stepDeactivate:
                $startTime = $this->getStartTime();

                return $startTime !== null
                    ? $this->importDeactivate($startTime)
                    : $this->failure('Cannot get start time of session, url: '.$this->request->fullUrl()."\nRegexp: (\d{4}-\d\d-\d\d)_(\d\d:\d\d:\d\d)");
                break;

            case $this->stepComplete:
                return $this->importComplete();
                break;

            case $this->stepQuery:
                return $this->processQuery();
                break;

            case $this->stepSuccess:
                if($type === 'sale') {
                    return $this->saleSuccess();
                }

                return '';
        }

        return $this->failure();
    }

    protected function getStartTime()
    {
        foreach (array_keys($this->request->all()) as $item) {
            if(preg_match("/(\d{4}-\d\d-\d\d)_(\d\d:\d\d:\d\d)/", $item, $matches)) {
                return "$matches[1] $matches[2]";
            }
        }

        return null;
    }

    /**
     * Проверка SCRF
     *
     * @return bool
     */
    protected function checkCSRF($mode)
    {
        if (!config('protocolExchange1C.isBitrixOn1C', false)
            || $mode === $this->stepCheckAuth) {
            return true;
        }

        // 1С-Битрикс пихает CSRF в любое место запроса, тоэтому только перебором
        foreach ($this->request->all() as $key => $item) {
            if ($key === Session::token()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Сообщение о ошибке
     *
     * @param string $details - детали, строки должны быть разделены /n
     *
     * @return string
     */
    protected function failure($details = '')
    {
        $return = "failure".(empty($details) ? '' : "\n$details");

        return $this->answer($return);
    }

    /**
     * Ответ серверу
     *
     * @param $answer
     *
     * @return string
     */
    protected function answer($answer)
    {
        return iconv('UTF-8', 'windows-1251', $answer);
    }

    /**
     * Попытка входа
     * @return bool
     */
    protected function userLogin()
    {
        if (Auth::getUser() === null) {
            $user = \Request::getUser();
            $pass = \Request::getPassword();

            $attempt = Auth::attempt(['email' => $user, 'password' => $pass]);

            if (! $attempt) {
                return false;
            }

            $gates = config('protocolExchange1C.gates', []);
            if (! is_array($gates)) {
                $gates = [$gates];
            }

            foreach ($gates as $gate) {
                if (Gate::has($gate) && Gate::denies($gate, Auth::user())) {
                    Auth::logout();

                    return false;
                }
            }

            return true;
        }

        return true;
    }

    /**
     * Авторизация 1с в системе
     *
     * @param string $type sale или catalog
     *
     * @return string
     */
    protected function checkAuth($type)
    {
        $cookieName = config('session.cookie');

        if (! empty(config('protocolExchange1C.sessionID'))) {
            $cookieID = config('protocolExchange1C.sessionID');
            Session::setId($cookieID);
            Session::flush();
            Session::regenerateToken();
        } else {
            $cookieID = Session::getId();
        }

        $answer = "success\n$cookieName\n$cookieID";

        if (config('protocolExchange1C.isBitrixOn1C', false)) {
            if ($type === 'catalog') {
                $answer .= "\n".csrf_token()."\n".date('Y-m-d_H:i:s');
            } elseif ($type === 'sale') {
                $answer .= "\n".csrf_token();
            }
        }

        return $this->answer($answer);
    }

    /**
     * Инициализация соединения
     *
     * @param string $type sale или catalog
     *
     * @return string
     */
    protected function init($type)
    {
        $zip = "zip=".($this->canUseZip() ? 'yes' : 'no');
        $limit = config('protocolExchange1C.maxFileSize');
        $answer = "$zip\n$limit";

        if (config('protocolExchange1C.isBitrixOn1C', false)) {
            if ($type === 'catalog' || $type === 'sale') {
                $answer .=
                    "\n".Session::getId().
                    "\n".config('protocolExchange1C.catalogXmlVersion');
            }
        }

        return $this->answer($answer);
    }

    /**
     * Можно ли использовать ZIP
     * @return bool
     */
    protected function canUseZip()
    {
        return class_exists('ZipArchive');
    }

    /**
     * Получение файла(ов)
     * @return string
     */
    protected function getFile()
    {
        $modelFileName = new FileName($this->request->input('filename'));
        $fileName = $modelFileName->getFileName();

        if (empty($fileName)) {
            return $this->failure('Mode: '.$this->stepFile
                                  .', parameter filename is empty');
        }

        $fullPath = $this->getFullPathToFile($fileName, false);

        $fData = $this->getFileGetData();

        if (empty($fData)) {
            return $this->failure('Mode: '.$this->stepFile
                                  .', input data is empty.');
        }

        if ($file = fopen($fullPath, 'ab')) {
            $dataLen = mb_strlen($fData, 'latin1');
            $result = fwrite($file, $fData);

            if ($result === $dataLen) {
                // файлы, требующие распаковки
                $files = [];

                if ($this->canUseZip()) {
                    $files = session('inputZipped', []);
                    $files[$fileName] = $fullPath;
                }

                session(['inputZipped' => $files]);

                return $this->success();
            }

            $this->failure('Mode: '.$this->stepFile
                           .', can`t wrote data to file: '.$fullPath);
        } else {
            return $this->failure('Mode: '.$this->stepFile.', cant open file: '
                                  .$fullPath.' to write.');
        }

        return $this->failure('Mode: '.$this->stepFile.', unexpected error.');
    }

    /**
     * Формирование полного пути к файлу
     *
     * @param string $fileName
     *
     * @param bool   $clearOld
     *
     * @return string
     */
    protected function getFullPathToFile($fileName, $clearOld = false)
    {
        $workDirName = $this->checkInputPath();

        if ($clearOld) {
            $this->clearInputPath($workDirName);
        }

        $path = config('protocolExchange1C.inputPath');

        return $path.'/'.$workDirName.'/'.$fileName;
    }

    /**
     * Формирование имени папки, куда будут сохранятся принимаемые файлы
     * @return string
     */
    protected function checkInputPath()
    {
        $folderName = session('inputFolderName');

        if (empty($folderName)) {
            $folderName = date('Y-m-d_H-i-s').'_'.md5(time());

            $fullPath =
                config('protocolExchange1C.inputPath').DIRECTORY_SEPARATOR
                .$folderName;

            if (! File::isDirectory($fullPath)) {
                File::makeDirectory($fullPath, 0755, true);
            }

            session(['inputFolderName' => $folderName]);
        }

        return $folderName;
    }

    /**
     * Очистка папки, где хранятся входящие файлы от предыдущих принятых файлов
     *
     * @param $currentFolder
     */
    protected function clearInputPath($currentFolder)
    {
        $storePath = config('protocolExchange1C.inputPath');

        foreach (File::directories($storePath) as $path) {
            if (File::basename($path) != $currentFolder) {
                File::deleteDirectory($path);
            }
        }
    }

    /**
     * получение контента файла
     *
     * @return string
     */
    protected function getFileGetData()
    {
        /*if (function_exists("file_get_contents")) {
            $fData = file_get_contents("php://input");
        } elseif (isset($GLOBALS["HTTP_RAW_POST_DATA"])) {
            $fData = &$GLOBALS["HTTP_RAW_POST_DATA"];
        } else {
            $fData = '';
        }

        if (\App::environment('testing')) {
            $fData = \Request::getContent();
        }

        return $fData;
        */

        return \Request::getContent();
    }

    /**
     * Отправка ответа, что все в порядке
     * @return string
     */
    protected function success()
    {
        return $this->answer('success');
    }
}
