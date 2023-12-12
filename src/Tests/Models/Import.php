<?php
/**
 * Import.php
 * Date: 19.05.2017
 * Time: 10:50
 * Author: Maksim Klimenko
 * Email: mavsan@gmail.com
 */

namespace Mavsan\LaProtocol\Tests\Models;


class Import implements \Mavsan\LaProtocol\Interfaces\Import
{
    /**
     * Метод возвращает развернутый ответ статуса, или пустую строку. Необходим,
     * для отправки ответа к 1С, например:
     * 'обработано 800 записей'
     * или:
     * 'в файле обмена имеется информация о изображении, но его нет'
     *
     * Если таких сообщений несколько, они должны быть разделены символом \n
     *
     * @return string
     */
    public function getAnswerDetail()
    {
        return '';
    }

    public function import($fileName)
    {
        $fileName = \File::basename($fileName);

        $data = session('import'.$fileName, 0);
        session(['import'.$fileName => ++$data]);

        if ($data < 5) {
            return self::answerProgress;
        }

        return self::answerSuccess;
    }


}