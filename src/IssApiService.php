<?php

namespace Drupal\iss;

use Drupal\Core\Database\Connection;
use GuzzleHttp\Exception\RequestException;

/**
 * Class ApiService.
 */
class IssApiService {

  /**
   * Database connection.
   *
   * Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new IssApiService object.
   */
  public function __construct(Connection $connection) {
    $this->database = $connection;
  }

  public function createInvoice($p_general, $id_sale) {
    $config =  \Drupal::config('iss.settings');
    $ppss_config =  \Drupal::config('ppss.settings');
    $client = \Drupal::httpClient();
    $datosFactura = [];
    //obtener el pago la cual se va facturar
    $ppss_sales = \Drupal::database()->select('ppss_sales_details', 'sd');
    $ppss_sales->join('ppss_sales', 's', 'sd.sid = s.id');
    $ppss_sales->condition('sd.id', $id_sale);
    $ppss_sales->fields('sd', ['id', 'created','tax', 'price', 'total']);
    $ppss_sales->fields('s', ['uid','details']);
    $sales = $ppss_sales->execute()->fetchAssoc();
    $details = json_decode($sales['details']);//detalle de la venta
    //obtener los datos fiscales del usuario
    $user = $this->database->select('iss_user_invoice', 'i')->condition('uid', $sales['uid'])->fields('i')->execute()->fetchAssoc();
    //obtener el ultimo folio de las facturas generadas
    $folio = $this->database->query("SELECT max(folio) as folio from {iss_invoices}")->fetchAssoc();
    
    try {
      $conceptos = array();
      $unconcepto = array();
      $base = $sales['price'];
      $importe = $sales['total'];
      $impuesto = $sales['tax'];
      $unconcepto = [
        'ObjetoImp' => '02',
        'ClaveProdServ' => '82101600',
        'NoIdentificacion' => '01',
        'Cantidad' => 1,
        'ClaveUnidad' => 'E48',
        'Descripcion' => $details->description,
        'ValorUnitario' => $base,
        'Importe' => $importe,
        'Descuento' => 0
      ];
      $impuestosTraslados = array(
        'Base' => $base,
        'Impuesto' => '002',
        'TipoFactor' => 'Tasa',
        'TasaOCuota' => '0.160000',
        'Importe' => $impuesto
      );
      $unconcepto['Impuestos']['Traslados'][0] = $impuestosTraslados;
      $conceptos[] = $unconcepto;

      $datosFactura['Version'] = '4.0';
      $datosFactura['Exportacion'] = '01';
      $datosFactura['Serie'] = $config->get('serie');
      $datosFactura['Folio'] = $folio['folio'] ? $folio['folio'] + 1 : $config->get('folio');
      $datosFactura['Fecha'] = 'AUTO';
      $datosFactura['FormaPago'] = $config->get('c_pago');
      $datosFactura['CondicionesDePago'] = "";
      $datosFactura['SubTotal'] = $base;
      $datosFactura['Descuento'] = null;
      $datosFactura['Moneda'] = $ppss_config->get('currency_code');
      $datosFactura['TipoCambio'] = 1;
      $datosFactura['Total'] = $importe;
      $datosFactura['TipoDeComprobante'] = 'I';
      $datosFactura['MetodoPago'] = "PUE";
      $datosFactura['LugarExpedicion'] = $config->get('lugar_expedicion');
      # opciones de personalización (opcionales)
      $datosFactura['LeyendaFolio'] = "FACTURA"; # leyenda opcional para poner a lado del folio: FACTURA, RECIBO, NOTA DE CREDITO, ETC.
      # Regimen fiscal del emisor ligado al tipo de operaciones que representa este CFDI
      $datosFactura['Emisor']['RegimenFiscal'] = $config->get('regimen_fiscal');

      if($p_general) {
        # Datos del receptor obligatorios
        $datosFactura['Receptor']['Rfc'] = 'XAXX010101000';
        $datosFactura['Receptor']['Nombre'] = 'PUBLICO EN GENERAL';
        $datosFactura['Receptor']['UsoCFDI'] = 'S01';//sin efectos fiscales
        $datosFactura["Receptor"]["DomicilioFiscalReceptor"] = $config->get('lugar_expedicion');//debe ser el mismo que LugarExpedición
        $datosFactura["Receptor"]["RegimenFiscalReceptor"] = '616';//sin obligaciones fiscales

        $datosFactura["InformacionGlobal"]["Periodicidad"] = '04';//01-Diaria, 02-Semanal, 03-Quincenal, 04-Mensual o 05-Bimestral
        $datosFactura["InformacionGlobal"]["Meses"] = date('m');
        $datosFactura["InformacionGlobal"]["Año"] = date('Y');

      } else {
        # Datos del receptor obligatorios
        $datosFactura['Receptor']['Rfc'] = $user['rfc'];
        $datosFactura['Receptor']['Nombre'] = $user['name'];
        $datosFactura['Receptor']['UsoCFDI'] = $user['cfdi'];
        $datosFactura["Receptor"]["DomicilioFiscalReceptor"] = $user['postal_code'];
        $datosFactura["Receptor"]["RegimenFiscalReceptor"] = $user['regimen_fiscal'];

        # Datos del receptor opcionales
        $datosFactura["Receptor"]["Calle"] = $user['address'];
        $datosFactura["Receptor"]["NoExt"] = $user['number_ext'];
        $datosFactura["Receptor"]["NoInt"] = $user['number_int'];
        $datosFactura["Receptor"]["Colonia"] = $user['suburb'];
        $datosFactura["Receptor"]["Loacalidad"] = $user['city'];;
        //$datosFactura["Receptor"]["Referencia"] = null;
        $datosFactura["Receptor"]["Municipio"] = $user['town'];
        $datosFactura["Receptor"]["Estado"] = $user['state'];
        $datosFactura["Receptor"]["Pais"] = 'MEXICO';
        $datosFactura["Receptor"]["CodigoPostal"] = $user['postal_code'];
      }

      $datosFactura['Conceptos'] = $conceptos;
      $datosFactura['Impuestos']['TotalImpuestosTrasladados'] = $impuesto;
      $datosFactura['Impuestos']['Traslados'][0]['Base'] = $base;
      $datosFactura['Impuestos']['Traslados'][0]['Impuesto'] = '002'; //002 = IVA, 003 = IEPS
      $datosFactura['Impuestos']['Traslados'][0]['TipoFactor'] = 'Tasa'; //Tasa, Cuota, Exento
      $datosFactura['Impuestos']['Traslados'][0]['TasaOCuota'] = '0.160000';
      $datosFactura['Impuestos']['Traslados'][0]['Importe'] = $impuesto;
      //conectar con el servicio
      $request = $client->post($config->get('api_endpoint').'/api/v5/invoice/create', [
        'headers' => ['X-Api-Key' => $config->get('api_key')],
        'form_params' => [ 'json' => json_encode($datosFactura)]
      ]);

      $response_body = $request->getBody();
      $data  = json_decode($response_body->getContents());
      if($data->code == '200') {
        // Save all transaction data in DB for future reference.
        $this->database->insert('iss_invoices')->fields([
          'sid' => $sales['id'],
          'folio' => $folio['folio'] ? $folio['folio'] + 1 : $config->get('folio'),
          'uuid' => $data->cfdi->UUID,
          'created' => $data->cfdi->FechaTimbrado,
          'pdf' => $data->cfdi->PDF,
          'xml' => $data->cfdi->XML,
          'p_general' => $p_general ? 1 : 0,
        ])->execute();
        if(!$p_general){
          $this->sendInvoice($data->cfdi->UUID, $user['mail']);
        }
        return $data;
      } else {
        return $data->message ?? 'Ha ocurrido un error';
      }
    } catch (RequestException $e) {
      if ($e->hasResponse()) {
        $exception = $e->getResponse()->getBody();
        $exception = json_decode($exception);
        return $exception->message ?? 'Error al generar factura';
      } else {
        \Drupal::logger('ISS')->error($e->getMessage());
        return 'Error al generar factura';
      }
    }
  }

  //send invoice
  function sendInvoice($uuid, $email){
    $client = \Drupal::httpClient();
    $config =  \Drupal::config('iss.settings');
    try {
      //enviar la factura por correo
      $request = $client->post($config->get('api_endpoint').'/api/v5/invoice/send', [
        'headers' => [
          'X-Api-Key' => $config->get('api_key'),
          'uuid' => $uuid,
          'recipient' => $email,
          'bbc' => '',
          'message' => 'Comprobante Fiscal Digital',
        ],
      ]);
      $response_body = $request->getBody();
      $data  = json_decode($response_body->getContents());
      return $data->message;
    } catch (RequestException $e) {
      if ($e->hasResponse()) {
        $exception = $e->getResponse()->getBody();
        $exception = json_decode($exception);
        return $exception->message ?? 'Error al enviar factura';
      } else {
        \Drupal::logger('ISS')->error($e->getMessage());
        return 'Error al enviar factura';
      }
    }
  }

  //funcion para generar facturas a público en general al final de mes
  public function globalInvoice() {
    $config =  \Drupal::config('iss.settings');
    $first_day = strtotime(date("Y-m-01"));//first day of the current month
    $start_day = strtotime(date("Y-m-t ".$config->get('time')."").$config->get('stamp_date'));//fecha y hora de inicio de generación de factura
    $today = \Drupal::time()->getRequestTime();
    $last_day = strtotime(date("Y-m-t 23:45:00").$config->get('stamp_date'));//last day of the current month
    //validar la fecha y hora del inicio de la generacíon de facturación
    if(($today > $start_day) && ($today < $last_day)) {
      //obtener los pagos recurrentes que no han sido facturados desde el primer hasta el ultimo dia del mes
      $query = $this->database->select('ppss_sales_details', 's');
      $query->leftJoin('iss_invoices', 'i', 's.id = i.sid');
      $query->condition('s.created', array($first_day, $last_day), 'BETWEEN');
      $query->fields('s', ['id']);
      $query->fields('i',['uuid']);
      $query->range(0, $config->get('num_rows'));
      $num = 0;
      $results = $query->execute()->fetchAll();
      //recorrer los pagos
      foreach($results as $result) {
        //validar que el pago no tenga factura generada
        if(!$result->uuid) {
          //llamar a la funcion para generar la factura
          $invoice = $this->createInvoice(true, $result->id);
          if($invoice->code ?? false && $invoice->code == '200') {
            //contador de facturas generadas
            $num = $num + 1;
          } else {
            \Drupal::logger('ISS')->error('Error al generar factura de la venta '.$result->id.'-'.$invoice);
          }
        }
      }
      if($num > 0) {
        \Drupal::logger('ISS')->info('Se generaron '.$num.' facturas publico en general');
      }
    }
  }
}