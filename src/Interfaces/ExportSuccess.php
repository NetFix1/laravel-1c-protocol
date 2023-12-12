<?php
/**
 * ExportSuccess.php
 * Date: 13.09.2020
 * Time: 14:03
 * Author: Maksim Klimenko
 * Email: mavsan@gmail.com
 */

namespace Mavsan\LaProtocol\Interfaces;

interface ExportSuccess
{
    /**
     * Метод, вызываемый контроллером после того, как данные были выгружены на
     * сервер
     * @return string|null
     */
    public function stepSuccess(): ?string;
}
