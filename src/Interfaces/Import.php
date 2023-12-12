<?php
/**
 * Import.php
 * Date: 18.05.2017
 * Time: 16:46
 * Author: Maksim Klimenko
 * Email: mavsan@gmail.com
 */

namespace Mavsan\LaProtocol\Interfaces;


interface Import
{
    const answerSuccess = 'success';
    const answerProgress = 'progress';
    const answerFailure = 'failure';

    /**
     * Обработка данных
     *
     * Метод должен возвращать self::answerSuccess, когда текущий файл
     * обработан
     * полностью (1С пошлет запрос на обработку следующего файла) или
     * self::answerProgress, если требуется (большой объем, длительное время
     * обработки и прочее) приостановить выполнение (тогда 1С пошлет снова
     * запрос на обработку текущего файла обмена), или self::answerFailure
     * в случае возникновения какой-либо ошибки. С любым из ответом можно
     * отправить комментарий, например: 'progress\nОбработано 700 записей из
     * 1000000', т.е. разделитель - перенос строки
     *
     * @param string $fileName полный путь к обрабатываемому файлу
     *
     * @return string success или progress, если какая-то ошибка - failure,
     *                далее /n и описание
     */
    public function import($fileName);

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
    public function getAnswerDetail();
}