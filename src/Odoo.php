<?php
namespace Ranchero\OdooPersat;
require_once(__DIR__ . '/../vendor/eteamsys/ripcord/ripcord.php');
class Odoo
{
  var $common;
  var $uid;
  var $password;
  var $db;
  var $formfields;
  var $url;
  var $models;
  var $cajas;
  var $location_id;
  var $mapped_cajas;
  function __construct($url, $db, $username, $password, $formmap = null, $cajasmap = null)
  {
    $this->formfields = $formmap;
    $this->cajas = $cajasmap;
    $this->db = $db;
    $this->password = $password;
    $this->url = $url;
    $this->common = \ripcord::client("$this->url/xmlrpc/2/common");
    $this->uid = $this->common->authenticate($db, $username, $password, array());
    $this->models = \ripcord::client("$this->url/xmlrpc/2/object");

    // $this->mapProductIds($formmap, $this->models);
    $this->mapped_cajas = $this->mapCajas($cajasmap, $this->models);
  }

  function mapCajas($cajasmap, $model)
  {
    $uniquecajas = array_unique(array_values($cajasmap));
    foreach ($uniquecajas as $caja) {
      var_dump($caja);
      $picks = $this->models->execute_kw(
          $this->db,
          $this->uid,
          $this->password,
          'stock.location',
          'search_read', //'fields_get',
          [[["name", "=", $caja]]],
          ['fields' => ['id', 'name']],
          );
      var_dump($picks);
      if (!count($picks)) {
        throw new \Exception(
            "Caja: " . $caja . " no encontrada"
            );
      }
      if (key_exists('faultCode', $picks)) {
        throw new Exception("Error inesperado buscando: $caja");
      }
      $cajasmap[(array_search($picks[0]['name'], $cajasmap))] = $picks[0];
    }
    return $cajasmap;
  }
  /*
   *
   * Esta funcion toma los nombres del los articulos y consigue su IDs
   *
   */
  function mapProductIds($formmap, $model)
  {
    foreach ($formmap as $formid => $formvalues) {
      var_dump($formid);
      foreach ($formmap[$formid] as $fromvaulekey => $formvalue) {
        var_dump(['KEY' => $fromvaulekey, 'VALUE' => $formvalue]);
        $picks = $this->models->execute_kw(
            $this->db,
            $this->uid,
            $this->password,
            'stock.quant',
            'search_read', //'fields_get',
            [],
            [
            'fields' => ['id', 'product_id', 'quantity', 'product_uom_id'],
            "domain" => ['&', ['product_id', '=', $formvalue], ['location_id', 'child_of', [26]]]
            ],
            );
        if (!count($picks)) {
          throw new Exception(
              "Producto: " . $formvalue . " no encontrado"
              );
        }
        if (key_exists('faultCode', $picks)) {
          throw new Exception("Error inesperado buscando: $formvalue");
        }
        var_dump($picks);
      }
    }
    exit;
  }
  function getCajas($ot)
  {
    if (!$caja_id = @$this->mapped_cajas[$ot['payload']['assignation_info']["responsibles"][0]["user_name"]]['id']) {
      error_log("El usuario " . $ot['payload']['assignation_info']['responsibles'][0]['user_name'] . " no esta mapeado a niguna caja");
      var_dump($ot);
      throw new Exception("El usuario " . $ot['payload']['assignation_info']['responsibles'][0]['user_name'] . " no esta mapeado a niguna caja");
      return false;
    }
    return $caja_id;
  }

  function preparaOt($ot)
  {
    $this->log($ot['payload']['_id'], "START:" . __FUNCTION__);
    $products = [];
    foreach ($this->formfields as $formid => $field) {
      // var_dump($field);
      if (!is_array(@$ot['payload']["wo_data"]["results"]["formvalues"][$formid][0])) {
        continue;
      }
      foreach (@$ot['payload']["wo_data"]["results"]["formvalues"][$formid][0] as $key => $qty) {
        // echo $key . "=>" . $field[$key] . "=>" . $qty . "\n";
        $qty = (float)$qty;
        if ($qty <= 0) {
          continue;
        }
        $picks = $this->models->execute_kw(
            $this->db,
            $this->uid,
            $this->password,
            'stock.quant',
            'search_read', //'fields_get',
            [],
            [
            'fields' => ['id', 'product_id', 'quantity', 'product_uom_id'],
            "domain" => ['&', ['product_id', '=', $field[$key]], ['location_id', 'child_of', [$this->getCajas($ot)]]]
            ],
            );
        // var_dump($picks);
        if (count($picks) and $qty > 0) {
          $product = [
            0,
            'garchola_33',
            [
              'product_id' => $picks[0]['product_id'][0],
              'location_id' => $this->getCajas($ot),
              'location_dest_id' => 5,
              'qty_done' => $qty,
              'product_uom_id' => $picks[0]['product_uom_id'][0],
            ]
          ];
              $products[] = $product;
              // return $products;
        }
      }
    }
    $this->log($ot['payload']['_id'] . "|cantidad de productos: " . count($products), "START:" . __FUNCTION__);
    return $products;

    // var_dump($ot['payload']["wo_data"]["results"]["formvalues"]);
  }

  function log($msg, $type = "none")
  {
    echo date('YmdHis') . "|" . $type . "|" . $msg . "\n";
  }
  function generaSalida($ot)
  {
    $this->log($ot['payload']['_id'], "START:" . __FUNCTION__);
    $this->location_id = null;
    $params = [
      'is_locked' => True,
      'immediate_transfer' => True,
      'partner_id' => False, //PARTNER ID CREARLO!!!
      'picking_type_id' => 2, //SALIDAS
      'location_id' => $this->getCajas($ot),
      'location_dest_id' => 5,
      'move_line_ids_without_package' => null,
      'move_type' => 'direct',
      'user_id' => 2,
      'note' => False
    ];
    $products = $this->preparaOt($ot);
    if (count($products)) {
      $params['partner_id'] = $this->generaContacto($ot);
      $params["move_line_ids_without_package"] = $products;
      $picks = $this->models->execute_kw(
          $this->db,
          $this->uid,
          $this->password,
          'stock.picking',
          'create',
          [$params]
          );
      if (!is_numeric($picks)) {
        throw new Exception("AAAAA");
      }
      $this->log($ot['payload']['_id'] . "|id:" . $picks, "START:" . __FUNCTION__);
      $rst = $this->validate($picks, $ot);
    }
  }

  function generaContacto($ot)
  {
    $nombre = $ot["payload"]["client"]["name"];
    $picks = $this->models->execute_kw(
        $this->db,
        $this->uid,
        $this->password,
        'res.partner',
        'search_read',
        [],
        [
        "fields" => ['name'],
        "domain" => [['name', '=', $nombre]]
        ]
        //[['company_id', 'in', [1, False]]]
        );
    var_dump($picks);
    if (is_array($picks) and count($picks) and key_exists('name', $picks[0])) {
      $this->log($ot['payload']['_id'] . "|nombre:" . $nombre . "|existe!|", "START:" . __FUNCTION__);
      return $picks[0]['id'];
    } else {
      $picks = $this->models->execute_kw(
          $this->db,
          $this->uid,
          $this->password,
          'res.partner',
          'create',
          [
          ['name' => $nombre]
          ]
          );
      if (!is_numeric($picks)) {
        var_dump($picks);
        $this->log($ot['payload']['_id'] . "|nombre:" . $nombre . "|error creano|", "START:" . __FUNCTION__);
        throw new Exception("AAAAA");
      }
      $this->log($ot['payload']['_id'] . "|nombre:" . $nombre . "|creado|", "START:" . __FUNCTION__);
      return $picks;
    }
  }
  function validate($id, $ot)
  {
    $picks = $this->models->execute_kw(
        $this->db,
        $this->uid,
        $this->password,
        'stock.picking',
        'button_validate',
        [$id]
        );
    if (is_array($picks)) {
      throw new Exception("Error inesperado!\n" . json_encode($picks));
    }
    $this->log($ot['payload']['_id'] . "|id:" . $picks, "START:" . __FUNCTION__);
    return $picks;
  }
}
