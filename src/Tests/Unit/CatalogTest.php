<?php
/**
 * CatalogTest.php
 * Date: 16.05.2017
 * Time: 18:40
 * Author: Maksim Klimenko
 * Email: mavsan@gmail.com
 */

namespace Mavsan\LaProtocol\Tests\Unit;


use App;
use App\User;
use Faker\Generator;
use File;
use Gate;
use Madzipper;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Mavsan\LaProtocol\Tests\Models\Import;
use Mavsan\LaProtocol\Tests\Models\ImportNotImplements;
use Mavsan\LaProtocol\Tests\Models\ImportWrongAnswer;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use DatabaseMigrations;

    /** @var  User созданный пользователь */
    protected $user;

    public function testCheckAuth()
    {
        // гейты тестируются дальше
        config(['protocolExchange1C.gates' => []]);

        $url = route('1sProtocolCatalog').'?type=catalog&mode=checkauth';
        $response = $this->get($url);

        $response->assertSeeText('failure');

        $header = $this->getAuthHeader();
        $response = $this->get($url, $header);

        $response->assertSeeText('success');

        $data = explode("\n", $response->getContent());
        define('cookieName', $data[1]);
        define('cookieID', $data[2]);
    }

    public function testCHeckGate()
    {
        config(['protocolExchange1C.gates' => ['testExchangeGateFalse']]);

        Gate::define('testExchangeGateFalse', function () {
            return false;
        });

        Gate::define('testExchangeGateTrue', function () {
            return true;
        });

        $url = route('1sProtocolCatalog').'?type=catalog&mode=checkauth';

        $header = $this->getAuthHeader();
        $response = $this->get($url, $header);

        $response->assertSeeText('failure');

        config([
            'protocolExchange1C.gates' =>
                ['testExchangeGateTrue', 'testExchangeGateFalse'],
        ]);

        $response = $this->get($url, $header);

        $response->assertSeeText('failure');

        config(['protocolExchange1C.gates' => ['testExchangeGateTrue']]);

        $response = $this->get($url, $header);

        $response->assertSeeText('success');
    }

    protected function getAuthHeader()
    {
        $email = config('protocolExchange1C.userEmailToTest');
        $pass = config('protocolExchange1C.userPasswordToTest');

        $data = [
            "HTTP_Authorization" => "Basic ".base64_encode("$email:$pass"),
            "PHP_AUTH_USER"      => $email,
            // must add this header since PHP won't set it correctly
            "PHP_AUTH_PW"        => $pass
            // must add this header since PHP won't set it correctly as well
        ];

        if (defined('cookieName')) {
            $data[cookieName] = cookieID;
        }

        return $data;
    }

    /**
     * Инициализация
     */
    public function testInit()
    {
        $url = route('1sProtocolCatalog').'?type=catalog&mode=init';
        $header = $this->getAuthHeader();
        $response = $this
            ->actingAs($this->user)
            ->get($url, $header);

        $response->assertSeeText(function_exists("zip_open") ? 'zip=yes'
            : 'zip=no');
        $response->assertSeeText('file_limit='
                                 .config('protocolExchange1C.maxFileSize'));
    }

    /**
     * Отправка файлов
     */
    public function testFile()
    {
        $url = route('1sProtocolCatalog').'?type=catalog&mode=file';
        $header = $this->getAuthHeader();

        $files = config('protocolExchange1C.filesToSendTest', []);
        $createFiles = false;

        if (empty($files)) {
            // файлы не переданы, создание временного файла
            $files[] = $this->createTmpFileToSend();

            $createFiles = true;

            config(['protocolExchange1C.maxFileSize' => 100]);
        }

        $session = [];

        foreach ($files as $fileName) {
            $this->assertFileExists($fileName,
                "Нет файла с каталогом от 1С: $fileName. Что тестировать, Карл?");

            $file = fopen($fileName, 'rb');
            $session = [];
            $baseName = File::basename($fileName);

            while (! feof($file)) {
                $sendSession = [];

                if (! empty($session)) {
                    $sendSession = [
                        'inputFolderName' => $session->get('inputFolderName'),
                        'inputZipped'     => $session->get('inputZipped'),
                    ];
                }

                $data = fread($file, config('protocolExchange1C.maxFileSize'));

                $response = $this
                    ->actingAs($this->user)
                    ->withSession($sendSession)
                    ->call('POST', $url.'&&filename='.$baseName, [], [], [],
                        $header, $data);

                $session = app('session.store');

                $response->assertSeeText('success');
                // имя папки, в которую сохраняются принимаемые данные
                $response->assertSessionHas('inputFolderName');
                // файлы, требующие распаковки
                $response->assertSessionHas('inputZipped');

                if (function_exists("zip_open")) {
                    $zip = $session->get('inputZipped');
                    $this->assertArrayHasKey(File::basename($fileName), $zip);
                }
            }
            fclose($file);
        }

        if ($createFiles) {
            foreach ($files as $file) {
                File::delete($file);
            }
        }

        return $session;
    }

    /**
     * Создание временного файла для отправки
     * @return string
     */
    protected function createTmpFileToSend()
    {
        $fileName = 'fakeImportProtocolTest';
        $path = __DIR__.'/'.$fileName.'.txt';

        File::put($path,
            App::make(Generator::class)->realText(5000));

        if (function_exists("zip_open") && class_exists(Madzipper::class)) {
            $zipName = __DIR__.'/'.$fileName.'.zip';

            File::delete($zipName);

            Madzipper::make($zipName)->add($path)->close();

            File::delete($path);

            return $zipName;
        } else {
            return $path;
        }
    }

    /**
     * Импорт данных
     * @depends testFile
     */
    public function testImport($session)
    {
        $url = route('1sProtocolCatalog').'?type=catalog&mode=import';
        $header = $this->getAuthHeader();

        $files = $this->getFilesToWorkTest();

        $model = config('protocolExchange1C.catalogWorkModel');
        if (empty($model)) {
            config(['protocolExchange1C.catalogWorkModel' => Import::class]);
        }

        foreach ($files as $file) {
            $sendSession = [];

            do {
                if (! empty($session)) {
                    $sendSession = $session->all();
                    unset($sendSession['_token']);
                }

                $response = $this
                    ->actingAs($this->user)
                    ->withSession($sendSession)
                    ->post($url.'&&filename='.$file, [], $header);

                $session = app('session.store');

                $response->assertDontSeeText('failure');

                $content = explode("\n", $response->getContent());
            } while ($content[0] == 'progress');

            $response->assertSeeText('success');
        }

        return $session;
    }

    /**
     * Файлы, для обработки
     * @return array|mixed
     */
    protected function getFilesToWorkTest()
    {
        $files = config('protocolExchange1C.filesToWorkTest', []);

        if (empty($files)) {
            $files = ['fakeImportProtocolTest.txt'];
        }

        return (array)$files;
    }

    /**
     * @depends testImport
     *
     * @return \Illuminate\Foundation\Application|\Illuminate\Session\Store
     */
    public function testImportWrongFileName($session)
    {
        $url = route('1sProtocolCatalog').'?type=catalog&mode=import&filename=';
        $header = $this->getAuthHeader();

        $sendSession = [];
        if (! empty($session)) {
            $sendSession = $session->all();
            unset($sendSession['_token']);
        }

        $response = $this
            ->actingAs($this->user)
            ->withSession($sendSession)
            ->post($url, [], $header);

        $response->assertSeeText('failure');
        $response->assertSeeText('Mode: import wrong file name');

        return app('session.store');
    }

    /**
     * @depends testImportWrongFileName
     *
     * @param $session
     *
     * @return \Illuminate\Foundation\Application|\Illuminate\Session\Store
     */
    public function testImportEmptyModel($session)
    {
        $files = $this->getFilesToWorkTest();

        $url = route('1sProtocolCatalog').'?type=catalog&mode=import&filename='
               .$files[0];
        $header = $this->getAuthHeader();

        $sendSession = [];
        if (! empty($session)) {
            $sendSession = $session->all();
            unset($sendSession['_token']);
        }

        config(['protocolExchange1C.catalogWorkModel' => '']);

        $response = $this
            ->actingAs($this->user)
            ->withSession($sendSession)
            ->post($url, [], $header);

        $response->assertSeeText('failure');
        $response->assertSeeText('Mode: import, please set model to import data in catalogWorkModel key');

        return app('session.store');
    }

    /**
     * @param $session
     *
     * @depends testImportEmptyModel
     *
     * @return \Illuminate\Foundation\Application|\Illuminate\Session\Store|mixed
     */
    public function testImportModelImplement($session)
    {
        $files = $this->getFilesToWorkTest();

        $url = route('1sProtocolCatalog').'?type=catalog&mode=import&filename='
               .$files[0];
        $header = $this->getAuthHeader();

        $sendSession = [];
        if (! empty($session)) {
            $sendSession = $session->all();
            unset($sendSession['_token']);
        }

        config(['protocolExchange1C.catalogWorkModel' => ImportNotImplements::class]);

        $response = $this
            ->actingAs($this->user)
            ->withSession($sendSession)
            ->post($url, [], $header);

        $response->assertSeeText('failure');
        $response->assertSeeText('Mode: import');
        $response->assertSeeText('must implement');

        return app('session.store');
    }

    /**
     * @param $session
     *
     * @depends testImportModelImplement
     *
     * @return \Illuminate\Foundation\Application|\Illuminate\Session\Store|mixed
     */
    public function testImportFileNotExists($session)
    {
        config(['protocolExchange1C.catalogWorkModel' => Import::class]);

        $files = $this->getFilesToWorkTest();

        $url = route('1sProtocolCatalog').'?type=catalog&mode=import&filename='
               .$files[0].'.fff';
        $header = $this->getAuthHeader();

        $sendSession = [];
        if (! empty($session)) {
            $sendSession = $session->all();
            unset($sendSession['_token']);
        }

        $response = $this
            ->actingAs($this->user)
            ->withSession($sendSession)
            ->post($url, [], $header);

        $response->assertSeeText('failure');
        $response->assertSeeText('Mode: import, file');
        $response->assertSeeText('not exists');

        return app('session.store');
    }

    /**
     * @param $session
     *
     * @depends testImportFileNotExists
     */
    public function testImportModelWrongAnswer($session)
    {
        config(['protocolExchange1C.catalogWorkModel' => ImportWrongAnswer::class]);

        $files = $this->getFilesToWorkTest();

        $url = route('1sProtocolCatalog').'?type=catalog&mode=import&filename='
               .$files[0];
        $header = $this->getAuthHeader();

        $sendSession = [];
        if (! empty($session)) {
            $sendSession = $session->all();
            unset($sendSession['_token']);
        }

        $response = $this
            ->actingAs($this->user)
            ->withSession($sendSession)
            ->post($url, [], $header);

        $response->assertSeeText('failure');
        $response->assertSeeText('Mode: import model');
        $response->assertSeeText('model return wrong answer');
    }

    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user =
            User::where('email', config('protocolExchange1C.userEmailToTest'))
                ->first();

        if (is_null($this->user)) {
            $this->user = factory(User::class, 'user')->create([
                'email'    => config('protocolExchange1C.userEmailToTest'),
                'password' => bcrypt(config('protocolExchange1C.userPasswordToTest')),
            ]);
        }
    }
}
