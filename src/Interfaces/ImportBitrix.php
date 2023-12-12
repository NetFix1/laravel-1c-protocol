<?php
/**
 * ImportBitrix.php
 * Date: 13.09.2020
 * Time: 15:06
 * Author: Maksim Klimenko
 * Email: mavsan@gmail.com
 */

namespace Mavsan\LaProtocol\Interfaces;


interface ImportBitrix
{
    /**
     * Метод, вызываемый, при получении команды от 1С deactivate
     *
     * @param string|null $startTime метка времени, когда был начат обмен
     *
     * @return string
     */
    public function modeDeactivate($startTime = null): string;

    /**
     * Метод, вызываемый командой complete, последний этап получения данных от
     * 1С Битрикс
     */
    public function modeComplete();
}
