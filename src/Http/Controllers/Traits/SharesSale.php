<?php
/**
 * Created by PhpStorm.
 * User: arsen
 * Date: 11/24/2018
 * Time: 9:08 PM
 */

namespace Mavsan\LaProtocol\Http\Controllers\Traits;


use Mavsan\LaProtocol\Interfaces\ExportOrders;
use Mavsan\LaProtocol\Interfaces\ExportOrdersSelf;
use Mavsan\LaProtocol\Interfaces\ExportSuccess;
use Mockery\Exception;

trait SharesSale
{
    /**
     * Получение модели, реализующей формирование данных о заказах
     *
     * @return \Mavsan\LaProtocol\Interfaces\ExportOrders|\Mavsan\LaProtocol\Interfaces\ExportOrdersSelf|string
     */
    protected function saleGetModel()
    {
        $modelClass = config('protocolExchange1C.saleShareModel');
        $modelSelfMakeXML = config('protocolExchange1C.saleShareToXML');

        if (empty($modelClass) && empty($modelSelfMakeXML)) {
            return $this->failure('Mode: '.$this->stepQuery
                                  .', please set model to export data in saleShareModel or saleShareToXML key.');
        }

        /** @var ExportOrders|ExportOrdersSelf $model */

        if ($modelClass) {
            $model = resolve($modelClass);

            if (! $model instanceof ExportOrders) {
                return
                    $this->failure('Mode: '.$this->stepQuery.' model '
                                   .$modelClass
                                   .' must implement \Mavsan\LaProtocol\Interfaces\ExportOrders');
            }
        } else {
            $model = resolve($modelSelfMakeXML);

            if (! $model instanceof ExportOrdersSelf) {
                return $this->failure('Mode: '.$this->stepQuery.' model '
                                      .$modelSelfMakeXML
                                      .' must implement \Mavsan\LaProtocol\Interfaces\ExportOrdersSelf');
            }
        }

        return $model;
    }

    /**
     * Формирование ответа на запрос данных о заказах
     * @return string
     */
    protected function processQuery()
    {
        $model = $this->saleGetModel();

        try {
            if ($model instanceof ExportOrders) {
                return $this->getOrdersDataInCommenceMlClient($model);
            } elseif ($model instanceof ExportOrdersSelf) {
                return $this->getOrdersDataSelf($model);
            }

            return $model;
        } catch (Exception $e) {
            return $this->failure($e->getMessage());
        }
    }

    /**
     * Формирование данных о заказах при помощи пакета arsengoian/commerce-ml
     *
     * @param \Mavsan\LaProtocol\Interfaces\ExportOrders $model
     *
     * @return mixed
     */
    protected function getOrdersDataInCommenceMlClient(ExportOrders $model)
    {
        if (! class_exists('CommerceML\Client')) {
            return $this->failure("Mode: $this->stepQuery, you must install composer package arsengoian/commerce-ml");
        }

        $cp = config('protocolExchange1C.encodeToWindows1251', true);
        $cp =
            $cp === true
                ? 'windows-1251'
                : ($cp === false ? 'utf-8' : $cp);

        return resolve('CommerceML\Client')
            ->toString($model->exportAllOrders(), $cp);
    }

    /**
     * Формирование данных о заказах при помощи своего собственного обработчика
     *
     * @param \Mavsan\LaProtocol\Interfaces\ExportOrdersSelf $model
     *
     * @return mixed
     */
    protected function getOrdersDataSelf(ExportOrdersSelf $model)
    {
        return $model->getXML();
    }

    /**
     * Завершение отправки данных в 1С о заказах, поступила команда 'success'
     * @return string|null
     */
    protected function saleSuccess()
    {
        $model = $this->saleGetModel();

        if($model instanceof ExportSuccess) {
            return $model->stepSuccess();
        }

        return 'success';
    }

}
