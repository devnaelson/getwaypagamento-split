<?php

namespace W2wc\PayBoleto;

use PagarMe;

class PlayĺoadBoleto
{

  public function __construct()
  {
  }
  public function run($wc, $get_order)
  {
    if (!empty($wc->get_option('api_keyw2'))) {
      $pagarme = new PagarMe\Client($wc->get_option('api_keyw2'));
      return json_decode(json_encode($pagarme->transactions()->create($this->mount($get_order, $wc))), true);
    } else {
      wc_add_notice("API_KEY Empty", 'error');
    }
  }

  private function mount($order, $wc)
  {
    global $wpdb, $woocommerce;
    $master = $wpdb->get_results("SELECT * FROM bd_usermeta where meta_key = 'recebedormaster' and meta_value = 'sim' ");
    $secondary = $wpdb->get_results("SELECT * FROM bd_usermeta where meta_key = 'recebedorsecundario' and meta_value = 'sim' ");
    $secondarycomission = $wpdb->get_results("SELECT * FROM bd_usermeta where meta_key = 'commissionrecebedorsecundario' ");
    $user = $secondary[0]->user_id;
    $secondarycomission = $wpdb->get_results("SELECT meta_value FROM bd_usermeta where meta_key = 'commissionrecebedorsecundario' and user_id = $user ");
    $desconto = $wc->get_option('boleto_desconto_percentage');
    $desconto = str_replace(".", ",", number_format($woocommerce->cart->cart_contents_total * ($desconto / 100), 2));
    $playload = [
      'amount' => str_replace(".", "", $order->get_total()),
      'payment_method' => 'boleto',
      'soft_descriptor' => $wc->get_option('soft_descriptor'),
      'boleto_instructions' => 'Pedido número: ' . $order->get_id() . ' no site: ' . home_url(),
      'boleto_expiration_date' => str_replace("/", "-", date("Y-m-d", strtotime('+' . $wc->get_option('boleto_expiration_date') . 'days'))),
      'boleto_fine.days' => $wc->get_option('boleto_expiration_date'),
      'boleto_fine.percentage' => $wc->get_option('boleto_fine_percentage'),
      'boleto_interest.days' => $wc->get_option('boleto_expiration_date'),
      'boleto_interest.percentage' => $wc->get_option('boleto_interest_percentage'),
      'capture' => true,
      'postback_url' => WC()->api_request_url($wc->id),
      'async' => false,
      'boleto_rules' => [
        "no_strict",
      ],
      'customer' => [
        'external_id' => strval($order->get_user_id()),
        'name' => $order->get_billing_first_name() . " " . $order->get_billing_last_name(),
        'birthday' => date_format(date_create(str_replace("/", "-", $order->billing_birthdate)), "Y-m-d"),
        'email' => $order->get_billing_email(),
        'type' => ($order->billing_persontype == 1) ? 'individual' : 'corporation',
        'country' => strtolower($order->get_billing_country()),
        'documents' => [
          [
            'type' => ($order->billing_persontype == 1) ? 'cpf' : 'cnpj',
            'number' => ($order->billing_persontype == 1) ? $order->billing_cpf  : $order->billing_cnpj
          ]
        ],
        'phone_numbers' => ["+" . str_replace("(", "", str_replace(")", "", str_replace(" ", "", str_replace("-", "", $order->get_billing_phone()))))]
      ],
      'metadata' => [
        'order_id' => $order->get_id(),
        'site' => home_url(),
        'desconto' => 'R$ ' . $desconto,
        'frete_total' => 'R$ ' . str_replace(".", ",", number_format($order->shipping_total, 2))
      ],
      'billing' => [
        'name' => $order->get_billing_first_name() . " " . $order->get_billing_last_name(),
        'address' => [
          'country' => strtolower($order->get_billing_country()),
          'street' => $order->get_billing_address_1(),
          'street_number' => $order->billing_number,
          'complementary' => $order->get_billing_address_2() . ' ',
          'state' => $order->get_billing_state(),
          'city' => $order->get_billing_city(),
          'neighborhood' => $order->billing_neighborhood,
          'zipcode' => str_replace("-", "", $order->get_billing_postcode())
        ]
      ],
    ];

    $comissionsecundario = $secondarycomission[0]->meta_value;
    if (isset($master[0]->user_id)) {

      $vendedores = array();
      $v_master = 0;
      $product_vendor = array();

      //process splits commission and shipping for product vendores
      $line_items = $order->get_items('line_item');
      $item_meta_key = wp_get_post_parent_id($order->id) ? '_commission_id' : '_child__commission_id';
      foreach ($line_items as $line_item_id => $line_item) {

        $commission_id   = wc_get_order_item_meta($line_item_id, $item_meta_key);
        $commission      = YITH_Commission($commission_id);

        if ($commission->exists()) {
          $comiss = $commission->get_rate() * 100;
          //$comiss = explode(".", $comiss);
          //$comiss = $comiss[0];

          $_product = $commission->get_product();
          $_quantity = $commission->get_item()['quantity'];

          $vendedores[$commission->user_id]['recipient_id'] = get_the_author_meta('idrecebedor', $commission->user_id);
          $vendedores[$commission->user_id]['v_vendor_total_price'] += $_quantity * $_product->price;
          $vendedores[$commission->user_id]['v_comiss'] = $comiss;

          //get all product of vendor
          $index_vendor = (empty($product_vendor[$commission->user_id])) ? 0 : count($product_vendor[$commission->user_id]);
          if ($_product->variation_id > 0) {
            $product_vendor[$commission->user_id][$index_vendor] = $_product->variation_id;
            $vendedores[$commission->user_id][$_product->variation_id] = $_quantity;
          } else {
            $product_vendor[$commission->user_id][$index_vendor] = $_product->id;
            $vendedores[$commission->user_id][$_product->id] = $_quantity;
          }
        }
      }

      $Secondary = (isset($secondary[0]->user_id) ? true : false);
      $produtos_vendor = array();
      $cnt_produtos_vdr = 0;

      $vendedores_keys = array_keys($vendedores);
      $count_vendedorKey = count($vendedores_keys);
      for ($n = 0; $n < $count_vendedorKey; $n++) {
        $v2_vendor = $vendedores[$vendedores_keys[$n]]['v_vendor_total_price'];
        $v2_comiss = $vendedores[$vendedores_keys[$n]]['v_comiss'];
        $v_master += ((100 - $v2_comiss) * $v2_vendor) / 100;
      }

      $v2 = 0;
      //if vendor exist
      $countVendors = count($vendedores);
      for ($k = 0; $k < $countVendors; $k++) {

        $UVendor_id = $vendedores_keys[$k];
        $v2_vendor = $vendedores[$vendedores_keys[$k]]['v_vendor_total_price'];
        $v2_comiss = $vendedores[$vendedores_keys[$k]]['v_comiss'];
        $v_vendor = ($v2_vendor * $v2_comiss) / 100;
        $v_vendor1 = 0;

        $product_sem = false;
        $product_sem_index = 0;
        $product_com = false;
        $product_com_index = 0;

        for ($i_r = 0; $i_r < count($product_vendor[$UVendor_id]); $i_r++) {

          $produtos_vendor[$cnt_produtos_vdr] = $product_vendor[$UVendor_id][$i_r];
          $cnt_produtos_vdr++;

          $rcb_product_id = $product_vendor[$UVendor_id][$i_r]; // Aqui contem variação e ids de produtos que não tem variação
          $recover_productID = wc_get_product($rcb_product_id)->id; // Pega a variação e busca de volta o id do produto, porque a comissão
          $search_byIDProduct = $recover_productID;
          $product_qtd = $vendedores[$UVendor_id][$rcb_product_id];
          $rcberComissProduct = $wpdb->get_results("SELECT * FROM bd_postmeta  WHERE post_id = $search_byIDProduct AND meta_key LIKE '%_vendor%' ");

          if (count($rcberComissProduct) > 0) {

            //Produtos com recebedores
            $meta_id = array();
            $meta_comiss = array();
            $meta_Rcbkey = array();
            $meta_ProductPrice = array();
            $c_id = 0;
            $c_comiss = 0;
            for ($n = 0; $n < count($rcberComissProduct); $n++) {
              $pattern_c = '/[c]/';
              if (preg_match($pattern_c, $rcberComissProduct[$n]->meta_key, $matches, PREG_OFFSET_CAPTURE) == 1) {
                $meta_comiss[$c_comiss] = $rcberComissProduct[$n]->meta_value;
                $c_comiss++;
              } else {
                $meta_id[$c_id] = $rcberComissProduct[$n]->meta_value;
                $meta_Rcbkey[$c_id] = get_user_meta($rcberComissProduct[$n]->meta_value, 'idrecebedor')[0];
                $_product = wc_get_product($rcb_product_id);
                $meta_ProductPrice[$c_id] = $_product->price * $product_qtd;
                $c_id++;
              }
            }

            $countMeta_Rcbkey = count($meta_Rcbkey);
            for ($n_r = 0; $n_r < $countMeta_Rcbkey; $n_r++) {

              $result = ($meta_ProductPrice[$n_r] * $v2_comiss) / 100;
              $v_vendorRcber = ((($n_r > 0) ? $v_vendor : $result) * $meta_comiss[$n_r]) / 100;
              $v_vendor = ((($n_r > 0) ? $v_vendor : $result) - $v_vendorRcber);

              $countRcber = count($playload['split_rules']);
              $playload['split_rules'][$countRcber]['amount'] = $v_vendorRcber;
              $playload['split_rules'][$countRcber]['recipient_id'] = $meta_Rcbkey[$n_r];
              $playload['split_rules'][$countRcber]['charge_processing_fee'] = false;
              $playload['split_rules'][$countRcber]['liable'] = true;
            }

            if ($product_sem == true) {
              $index = count($playload['split_rules']);
              $playload['split_rules'][$product_sem_index]['amount'] = $v_vendor + $v_vendor1;
            } else {

              if ($product_com == false) {
                $index = count($playload['split_rules']);
                $product_com_index = $index;
                $playload['split_rules'][$product_com_index]['amount'] += $v_vendor + $v_vendor1;
                $playload['split_rules'][$product_com_index]['recipient_id'] = $vendedores[$vendedores_keys[$k]]['recipient_id'];
                $playload['split_rules'][$product_com_index]['charge_processing_fee'] = false;
                $playload['split_rules'][$product_com_index]['liable'] = true;
              }

              if ($product_com == true) {
                $playload['split_rules'][$product_com_index]['amount'] += $v_vendor;
              }

              $product_com = true;
            }
          }

          if (count($rcberComissProduct) == 0) {

            //produtos sem recebedores
            $_product = wc_get_product($rcb_product_id);
            $v_vendor1 = (($_product->price * $product_qtd) * $v2_comiss) / 100;

            if ($product_sem == false) {
              $index = count($playload['split_rules']);
              $playload['split_rules'][$index]['amount'] = $v_vendor1;
              $playload['split_rules'][$index]['recipient_id'] = $vendedores[$vendedores_keys[$k]]['recipient_id'];
              $playload['split_rules'][$index]['charge_processing_fee'] = false;
              $playload['split_rules'][$index]['liable'] = true;
              $product_sem_index = $index;
            }

            if ($product_sem == true) {
              $playload['split_rules'][$product_sem_index]['amount'] += $v_vendor1;
            }
            $product_sem = true;
            $v2 += $v_vendor1;
          }
        }
      }

      $total_sem_vendor = 0; //Produtos que não tem vendedores
      foreach ($order->get_items() as $item) {
        $is = false;
        for ($j = 0; $j < count($produtos_vendor); $j++) {
          if ($item['product_id'] == $produtos_vendor[$j] || $item['variation_id'] == $produtos_vendor[$j]) {
            $is = true;
          }
        }
        if ($is == false) {
          $total_sem_vendor += $item['total'];
        }
      }

      $v_secondary = (($v_master + $total_sem_vendor) * $comissionsecundario) / 100;
      $v_master = (($v_master + $total_sem_vendor) - $v_secondary);

      if ($Secondary == true) {

        if (count($vendedores_keys) > 0) {

          $size_t = count($playload['split_rules']);
          $playload['split_rules'][$size_t]['amount'] = $v_master;
          $playload['split_rules'][$size_t]['recipient_id'] = get_the_author_meta('idrecebedor', $master[0]->user_id);
          $playload['split_rules'][$size_t]['charge_processing_fee'] = true;
          $playload['split_rules'][$size_t]['liable'] = true;

          $playload['split_rules'][$size_t + 1]['amount'] = $v_secondary;
          $playload['split_rules'][$size_t + 1]['recipient_id'] = get_the_author_meta('idrecebedor', $secondary[0]->user_id);
          $playload['split_rules'][$size_t + 1]['charge_processing_fee'] = false;
          $playload['split_rules'][$size_t + 1]['liable'] = true;
        } else {

          $size_t = 0;
          $playload['split_rules'][$size_t]['amount'] = $v_master;
          $playload['split_rules'][$size_t]['recipient_id'] = get_the_author_meta('idrecebedor', $master[0]->user_id);
          $playload['split_rules'][$size_t]['charge_processing_fee'] = true;
          $playload['split_rules'][$size_t]['liable'] = true;

          $playload['split_rules'][$size_t + 1]['amount'] = $v_secondary;
          $playload['split_rules'][$size_t + 1]['recipient_id'] = get_the_author_meta('idrecebedor', $secondary[0]->user_id);
          $playload['split_rules'][$size_t + 1]['charge_processing_fee'] = false;
          $playload['split_rules'][$size_t + 1]['liable'] = true;
        }
      } else {

        if (count($vendedores_keys) > 0) {
          $size_t = count($playload['split_rules']);
          $playload['split_rules'][$size_t]['amount'] = $v_master;
          $playload['split_rules'][$size_t]['recipient_id'] = get_the_author_meta('idrecebedor', $master[0]->user_id);
          $playload['split_rules'][$size_t]['charge_processing_fee'] = true;
          $playload['split_rules'][$size_t]['liable'] = true;
        } else {
          $playload['split_rules'][0]['amount'] = $v_master;
          $playload['split_rules'][0]['recipient_id'] = get_the_author_meta('idrecebedor', $master[0]->user_id);
          $playload['split_rules'][0]['charge_processing_fee'] = true;
          $playload['split_rules'][0]['liable'] = true;
        }
      }

      if (FRETE_VENDEDOR == 'yes') {

        $frete_vendores = array();
        $frete_counter = 0;
        for ($k = 0; $k < $countVendors; $k++) {
          $UVendor_id = $vendedores_keys[$k];
          $keyVendor = get_user_meta($UVendor_id, 'idrecebedor')[0];
          for ($i_r = 0; $i_r < count($product_vendor[$UVendor_id]); $i_r++) {
            $rcb_product_id = $product_vendor[$UVendor_id][$i_r]; // aqui contem variação e ids de produtos que não tem variação
            $_product = wc_get_product($rcb_product_id);
            $orderid = $order->get_id();
            $order_item = $wpdb->get_results("SELECT * FROM bd_woocommerce_order_items where order_id = '$orderid' and order_item_type = 'shipping' ");
            foreach ($order_item as $orderitem2) {
              $order_item_id = $orderitem2->order_item_id;
              $order_item_name = $orderitem2->order_item_name;
              $order_item_meta = $wpdb->get_results("SELECT * FROM bd_woocommerce_order_itemmeta where order_item_id = '$order_item_id' ");
              $custoFrete = 0;
              foreach ($order_item_meta as $order_item_meta2) {
                if ($order_item_meta2->meta_key == 'cost') {
                  $custoFrete = $order_item_meta2->meta_value;
                }

                if ($order_item_meta2->meta_key == '_product_ids') {
                  if ($_product->id == $order_item_meta2->meta_value) {
                    $vendor = yith_get_vendor($UVendor_id, 'user');
                    $playload['metadata'][count($playload['metadata'])]['frete_' . $vendor->name] = $order_item_name . ': R$ ' . str_replace(".", ",", number_format($custoFrete, 2));
                    for ($v = 0; $v < count($playload['split_rules']); $v++) {
                      if ($playload['split_rules'][$v]['recipient_id'] == $keyVendor) {

                        $playload['split_rules'][$v]['amount'] += $custoFrete;
                        $frete_vendores[$frete_counter] = $custoFrete;
                        $frete_counter++;
                        break;
                      }
                    }
                  }
                }
              }
            }
          }
        }

        $val_allFrete = 0;
        for ($n = 0; $n < count($frete_vendores); $n++) {
          $val_allFrete += $frete_vendores[$n];
        }

        $val_fretesV = ($order->shipping_total - $val_allFrete);
        $playload['metadata'][count($playload['metadata'])]['frete_sem_vendedor'] = $order_item_name . ': R$ ' . str_replace(".", ",", number_format($val_fretesV, 2));

        for ($d = 0; $d < count($playload['split_rules']); $d++) {
          if ($playload['split_rules'][$d]['recipient_id'] == get_the_author_meta('idrecebedor', $master[0]->user_id)) {
            $playload['split_rules'][$d]['amount'] += $val_fretesV;  //frete pra master
          }
        }
      }
      //fim frete vendores

      $fixTotal = 0;
      for ($t = 0; $t < count($playload['split_rules']); $t++) $fixTotal +=  str_replace(".", "", number_format($playload['split_rules'][$t]['amount'], 2, "", ""));

      $add_signal = 0;
      $centavos = ($fixTotal - $playload['amount']);

      if ($centavos == 0) {
        $add_signal = $add_signal;     // Faz nada se der igual ao total.
      } else if ($centavos < 0) {      // Negativo
        wLog("user_id:" . $order->id . " | order_id:" . $order->get_user_id() . " valor:" . $centavos, "CENTAVOS", "boleto_centavos");
        $add_signal = abs($centavos);
      }

      //Correção se passar como positvo.
      else if ($centavos > 0) {
        wLog("user_id:" . $order->id . " | order_id:" . $order->get_user_id() . " valor:" . $centavos, "CENTAVOS", "boleto_centavos");
        $add_signal = -$centavos;
      }

      $enter = false;
      for ($end = 0; $end < count($playload['split_rules']); $end++) {
        $playload['split_rules'][$end]['amount'] = str_replace(".", "", number_format($playload['split_rules'][$end]['amount'], 2, "", ""));
        //Retira de master
        if ($playload['split_rules'][$end]['recipient_id'] == get_the_author_meta('idrecebedor', $master[0]->user_id) && $enter == false) {
          $playload['split_rules'][$end]['amount'] += $add_signal; //Adiciona para secundario
          $enter = true;
        }
      }
    }

    $counter_toItems = 0;
    foreach ($order->get_items() as $item) {
      $product_ = $item->get_product();
      $playload['items'][$counter_toItems]['id']         = strval($item['product_id']);
      $playload['items'][$counter_toItems]['title']      = $item['name'];
      $playload['items'][$counter_toItems]['unit_price'] = str_replace(".", "", number_format($item['total'], 2, "", ""));
      $playload['items'][$counter_toItems]['quantity']   = $item['quantity'];
      $playload['items'][$counter_toItems]['tangible']   = ($product_->is_virtual() == true) ? false : true;
      $counter_toItems++;
    }

    wLog("User: " . $order->id . " Order: " . $order->get_user_id() . " " . json_encode($playload), 'PAYLOAD', 'boleto.log');
    return $playload;
  }

  public function fields($wc)
  {

    $wc->form_fields = apply_filters($wc->id, array(
      'enabled' => array(
        'title' => __('Habilitar/Desabilitar', 'boleto-pay'),
        'type' => 'checkbox',
        'label' => __('Habilitar ou Desabilitar Boleto', 'boleto-pay'),
        'default' => 'no'
      ),
      'pagarme_version' => array(
        'title' => __('Pagar.me versão API', 'boleto-pay'),
        'type' => 'text',
        'placeholder' =>  \PagarMe\PagarMe::VERSION,
        'desc_tip' => true,
        'description' => __('Versão SDK do pagar.me API.', 'boleto-pay'),
        'disabled' => true
      ),
      'title' => array(
        'title' => __('Título do boleto', 'boleto-pay'),
        'type' => 'text',
        'default' => __('Boleto', 'boleto-pay'),
        'desc_tip' => true,
        'description' => __('Título que aparecerá na tela Finalizar Compra para o Boleto', 'boleto-pay'),
        'custom_attributes' => array(
          'required' => 'required',
        ),
      ),
      'description' => array(
        'title' => __('Descrição do boleto', 'boleto-pay'),
        'type' => 'textarea',
        'default' => __('O boleto bancário será exibido após a confirmação e poderá ser impresso para pagamento em qualquer agência bancária, bankline (App) ou casas lotéricas.', 'boleto-pay'),
        'desc_tip' => true,
        'description' => __('Descrição para opção boleto na página Finalizar Compra', 'boleto-pay'),
        'custom_attributes' => array(
          'required' => 'required',
        ),
      ),
      'api_keyw2' => array(
        'title'             => __('Pagar.me Chave API', 'woocommerce-pagarme'),
        'type'              => 'text',
        'description'       => sprintf(__('Por favor digite sua chave Pagar.me API. Ela é necessária para o funcionamento do processo de pagamento por boleto, é possível encontra-la em %s', 'woocommerce-pagarme'), '<a href="https://beta.dashboard.pagar.me/#/settings" target="_blank">' . __('Pagar.me Dashboard > Configurações', 'woocommerce-pagarme') . '</a>'),
        'default'           => '',
        'custom_attributes' => array(
          'required' => 'required',
        ),
      ),
      'encryption_keyw2' => array(
        'title'             => __('Pagar.me Chave de Criptografia', 'woocommerce-pagarme'),
        'type'              => 'text',
        'description'       => sprintf(__('Por favor digite sua chave  de Criptografia. Ela é necessária para o funcionamento do processo de pagamento por boleto, é possível encontra-la em %s', 'woocommerce-pagarme'), '<a href="https://beta.dashboard.pagar.me/#/settings" target="_blank">' . __('Pagar.me Dashboard > Configurações', 'woocommerce-pagarme') . '</a>'),
        'default'           => '',
        'custom_attributes' => array(
          'required' => 'required',
        ),
      ),
      'soft_descriptor' => array(
        'title'             => __('Identificação no boleto', 'woocommerce-pagarme'),
        'type'              => 'text',
        'description'       => __('Texto que aparecerá no boleto do cliente para identificação do seu site (13 caracteres)', 'woocommerce-pagarme'),
        'default'           => '',
        'custom_attributes' => array(
          'required' => 'required',
          'maxlength' => 13,
        ),
      ),
      'boleto_expiration_date' => array(
        'title'             => __('Quantidade de dias para pagamento do boleto', 'woocommerce-pagarme'),
        'type'              => 'number',
        'description'       => __('Digite um número entre 1 (um dia) e 7 (sete dias), onde corresponde o número de dias que o cliente terá para pagar o boleto apartir da data de geração do boleto.', 'woocommerce-pagarme'),
        'default'           => '3',
        'custom_attributes' => array(
          'required' => 'required',
          'min' => 1,
          'max' => 7,
        ),
      ),
      'boleto_fine_percentage' => array(
        'title'             => __('Multa após o vencimento', 'woocommerce-pagarme'),
        'type'              => 'number',
        'description'       => __('Valor em porcentagem da taxa de multa (Valor máximo de 2% do valor do documento.)', 'woocommerce-pagarme'),
        'default'           => '0',
        'custom_attributes' => array(
          'required' => 'required',
          'min' => 0,
          'max' => 2,
        ),
      ),
      'boleto_interest_percentage' => array(
        'title'             => __('Juros após o vencimento', 'woocommerce-pagarme'),
        'type'              => 'number',
        'description'       => __('Valor em porcentagem da taxa de juros que será cobrado ao mês. (Valor máximo de 1% do valor do documento.)', 'woocommerce-pagarme'),
        'default'           => '0',
        'custom_attributes' => array(
          'required' => 'required',
          'min' => 0,
          'max' => 1,
        ),
      ),
      'boleto_desconto_percentage' => array(
        'title'             => __('Valor do desconto', 'woocommerce-pagarme'),
        'type'              => 'text',
        'description'       => __('Valor em porcentagem (%), do desconto que será concedido no pagamento por boleto', 'woocommerce-pagarme'),
        'default'           => '0',
        'custom_attributes' => array(
          'required' => 'required',
          'maxlength' => 4,
          'min' => 0,
          'max' => 100,
          'data-accept-dot' => 1,
        ),
      ),
    ));
  }
}