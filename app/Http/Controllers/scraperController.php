<?php

namespace App\Http\Controllers;

use Goutte\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use App\Models\Pharmacy;
use App\Models\Gard;
use App\Models\last_scrape_info;
use Carbon\Carbon;

class scraperController extends Controller
{
    public static function scraper($url, $cityName, $cityID) {
      $client = new Client(); $mainPage = $client->request('GET', $url);
      $urls = $mainPage->filter('article p > a')
      ->each(function($i) { return 'https://www.annuaire-gratuit.ma' . $i->attr('href'); });

      $results = array();
      $pharmaFails = 0;
      for($i = 0; $i < count($urls); $i++){
        try {
            $page = $client->request('GET', $urls[$i]);
            $name = trim(mb_strtoupper(str_replace('Pharmacie', '', $page->filter('div.col-xs-12 h1')->text())));
            $address =  trim(preg_replace("/[-]?[\s]?$cityName/i", '', $page->filter('div.col-xs-12 div.row.column_in')->first()->filter('tr')->first()->filter('td > address')->text())) . ", {$cityName}";
            $city = trim($page->filter('div.col-xs-12 div.row.column_in')->first()->filter('tr')->eq(1)->filter('td:last-child')->text());
            $phone = trim(str_replace(' ', '', $page->filter('div.col-xs-12 div.row.column_in')->first()->filter('tr')->eq(3)->filter('td:last-child')->text()));
            $location = trim($page->filter('div.col-xs-12 div.row.column_in')->first()->filter('tr')->first()->filter('td > address > a')->attr('href'));
            $lat = trim(explode(',', explode('q=', $location)[1])[0]);
            $long = trim(explode(',', explode('q=', $location)[1])[1]);
            
            $sDate[$i]  = $page->filter('table.pharma_history tr:not(:first-child)')->each(function($item, $i) {
                $date[$i] = $item->filter('td')->first()->text(); 
                if(Carbon::parse($date[$i]) <= Carbon::now()) {
                    $res = $date[$i];
                }
                return $res;
            });

            $startDateMax = max(array_map('strtotime', $sDate[$i]));
            $startDateMax = date('Y-m-j', $startDateMax);
            $startDateMax = $startDateMax . ' 08:00:00';

            $endDate[$i]  = $page->filter('table.pharma_history tr:not(:first-child)')->each(function($item, $i) {
                $date[$i] = $item->filter('td')->first()->text();
                if(Carbon::parse($date[$i]) <= Carbon::now()) {
                    $res = $item->filter('td')->eq(1)->text();
                }
                return $res;
            });
            
            $endDateMax = max(array_map('strtotime', $endDate[$i]));
            $endDateMax = date('Y-m-j', $endDateMax);
            $endDateMax = $endDateMax . ' 23:59:00';
            $guard_type = trim(str_replace('Garde', '', $page->filter('table.pharma_history tr')->last()->filter('td')->eq(2)->text()));
            
            $results[$i]['name'] = $name;
            $results[$i]['address'] = $address;
            $results[$i]['city'] = $city;
            $results[$i]['phone'] = $phone;
            $results[$i]['location'] = $location;
            $results[$i]['lat'] = $lat;
            $results[$i]['long'] = $long;
            $results[$i]['startDate'] = $startDateMax;
            $results[$i]['endDate'] = $endDateMax;
            
            if(preg_match('/^Jour et Nuit$/i', $guard_type)) { $results[$i]['guard-type'] = '24h'; }
            elseif(preg_match('/^Jour$/i', $guard_type)) { $results[$i]['guard-type'] = 'jour'; }
            elseif(preg_match('/^Nuit$/i', $guard_type)){ $results[$i]['guard-type'] = 'nuit'; }
            else { $results[$i]['guard-type'] = '24h'; }
              
          } catch (\Exception $e) { $pharmaFails++; continue; }
          
      }

      //reindex
      $arrays = $results; $datas = array(); $i=0;
      foreach($arrays as $k => $item){ $datas[$i] = $item; unset($arrays[$k]);   $i++; }

      //insert to mysql
      $pharmacyCount = 0;
      $pharmacyUpdated = 0;
      $gardCount = 0;

      for($i = 0; $i < count($datas); $i++) {
        $pharmacy = Pharmacy::query()->where("phone", "=", $datas[$i]['phone'])->first();
        if (!$pharmacy) {
            $resultpharma = Pharmacy::create([ 
              'name' =>  $datas[$i]['name'], 
              'address' =>  $datas[$i]['address'],
              'phone' =>  $datas[$i]['phone'], 
              'location_url' =>  $datas[$i]['location'],
              'lat' =>  $datas[$i]['lat'], 
              'long' =>  $datas[$i]['long'],
              'city_id' => $cityID 
            ]);
            $pharmacyCount ++;
            Gard::create([ 'startDate' =>  $datas[$i]['startDate'], 'endDate' =>  $datas[$i]['endDate'],
                'guard_type' =>  $datas[$i]['guard-type'], 'pharmacy_id' => $resultpharma->id ]);
            $gardCount ++;
        } elseif( $pharmacy->name == null ) {
            Pharmacy::where('id', '=', $pharmacy->id)
            ->update([
              'name' => $datas[$i]['name'],
              'address' => $datas[$i]['address'],
            ]);
            $pharmacyUpdated ++;
            Gard::create([
              'startDate' =>  $datas[$i]['startDate'], 
              'endDate' =>  $datas[$i]['endDate'], 
              'guard_type' =>  $datas[$i]['guard-type'],
              'pharmacy_id' => $pharmacy->id 
            ]);
             $gardCount ++;
        } else { 
            $gard = Gard::query()
            ->where("startDate", "=", $datas[$i]['startDate'])
            ->where("endDate", "=", $datas[$i]['endDate'])
            ->where('pharmacy_id', '=', $pharmacy->id)
            ->count();
            if(!$gard) {
                Gard::create([ 
                  'startDate' =>  $datas[$i]['startDate'], 
                  'endDate' =>  $datas[$i]['endDate'], 
                  'guard_type' =>  $datas[$i]['guard-type'],
                  'pharmacy_id' => $pharmacy->id ]);
                $gardCount ++;
            }
        }

      }

      $result = last_scrape_info::create([
          'executedBy' =>  'App_single',
          'city' =>  $cityName,
          'guards_added' => $gardCount,
          'pharmacies_added' => $pharmacyCount,
          'pharmacies_Updated' => $pharmacyUpdated,
          'pharmacies_fails_count' =>  $pharmaFails,
          'updated_at' => Carbon::now()
      ]);

      if(Session()->has('pharmacyCount') && Session()->has('gardCount')) {
        Session()->pull('pharmacyCount'); Session()->pull('gardCount');
      }
      Session()->put(['pharmacyCount' => $pharmacyCount, 'gardCount' => $gardCount]);
    
      if(Session()->has('pharmacyUpdated')) {
        Session()->pull('pharmacyUpdated');
      }
      Session()->put(['pharmacyUpdated' => $pharmacyUpdated]);
      
      return redirect()->route('dashboard');
    }

    public function scrapeLEMATIN() {
      $client = new Client(); 
      $mainPage = $client->request('GET', 'https://lematin.ma/pharmacie-garde-casablanca.html');

      $urls = $mainPage->filter('.lespharmaciengarde')->each(function($item) {
        return 'https://lematin.ma' . $item->filter('a')->attr('href');
      });

      for($i = 0; $i < count($urls); $i++) {
        $page = $client->request('GET', $urls[$i]);
      
        $sub_urls[$i] = $page->filter('.lespharmaciengarde .pharmacie')->each(function($item) {
          return 'https://lematin.ma' . $item->filter('h5 a')->attr('href');
        });
        
      }

      $main_urls = array();
      foreach ($sub_urls as $url) {
          $main_urls = array_merge($main_urls, $url);
      }

      $datas = array();
      for($i = 0; $i < count($main_urls); $i++) {
        $page = $client->request('GET', $main_urls[$i]);
      
        $datas[$i]['name'] = trim( str_replace('Pharmacie', '', $page->filter('.lespharmaciengarde h4 a')->first()->text()) );
        $datas[$i]['address'] = trim( str_replace( 'Adresse :', '', $page->filter('.lespharmaciengarde .infos .col-lg-8 p')->eq(1)->text()) );
        $datas[$i]['phone'] = trim( str_replace('.', '', str_replace('Tél :', '', $page->filter('.lespharmaciengarde .infos .col-lg-8 p')->eq(0)->text())) );
        $datas[$i]['startDate'] = $page->filter('.lespharmaciengarde .infos .col-lg-12 .table tr:nth-child(2) td')->eq(3)->text();
        $datas[$i]['endDate'] = $page->filter('.lespharmaciengarde .infos .col-lg-12 .table tr:nth-child(2) td')->eq(4)->text();
        
        if( $page->filter('.lespharmaciengarde .infos .col-lg-12 .table tr:nth-child(2) td')->eq(2)->children()->count() === 1 ) {
          $datas[$i]['guard-type'] = '24h';
        } elseif ( $page->filter('.lespharmaciengarde .infos .col-lg-12 .table tr:nth-child(2) td')->eq(0)->children()->count() === 1 ) {
          $datas[$i]['guard-type'] = 'jour';
        } elseif( $page->filter('.lespharmaciengarde .infos .col-lg-12 .table tr:nth-child(2) td')->eq(1)->children()->count() === 1 ) {
          $datas[$i]['guard-type'] = 'nuit';
        }
      }

      //INSERT To MySql
      $pharmacyCount = 0;
      $pharmacyUpdated = 0;
      $gardCount = 0;

      for($i = 0; $i < count($datas); $i++) {

        $pharmacy = Pharmacy::query()->where("phone", "=", $datas[$i]['phone'])->first();
        
        if (!$pharmacy) {
            $resultpharma = Pharmacy::create([ 
              'name' =>  $datas[$i]['name'], 
              'address' =>  $datas[$i]['address'],
              'phone' =>  $datas[$i]['phone'], 
              'city_id' => 1 
            ]);

            $pharmacyCount ++;

            Gard::create([ 
              'startDate' =>  $datas[$i]['startDate'], 
              'endDate' =>  $datas[$i]['endDate'],
              'guard_type' =>  $datas[$i]['guard-type'], 
              'pharmacy_id' => $resultpharma->id 
            ]);

            $gardCount ++;

        } 
        elseif( $pharmacy->name == null ) {
            Pharmacy::where('id', '=', $pharmacy->id)
            ->update([
              'name' => $datas[$i]['name'],
              'address' => $datas[$i]['address'],
            ]);

            $pharmacyUpdated ++;

            Gard::create([
              'startDate' =>  $datas[$i]['startDate'], 
              'endDate' =>  $datas[$i]['endDate'], 
              'guard_type' =>  $datas[$i]['guard-type'],
              'pharmacy_id' => $pharmacy->id 
            ]);

             $gardCount ++;

        } 
        else { 
            $gard = Gard::query()
            ->where("startDate", "=", $datas[$i]['startDate'])
            ->where("endDate", "=", $datas[$i]['endDate'])
            ->where('pharmacy_id', '=', $pharmacy->id)
            ->count();

            if(!$gard) {
                Gard::create([ 
                  'startDate' =>  $datas[$i]['startDate'], 
                  'endDate' =>  $datas[$i]['endDate'], 
                  'guard_type' =>  $datas[$i]['guard-type'],
                  'pharmacy_id' => $pharmacy->id ]);

                $gardCount ++;
            }
        }

      }
      $infos = array();
      $infos['pharmacyCount'] = $pharmacyCount;
      $infos['pharmacyUpdated'] = $pharmacyUpdated;
      $infos['gardCount'] = $gardCount;

      return $infos;
    }

    public function scrapeCasa() {
      $infos = $this->scrapeLEMATIN(); 
      
      $pharmacyCount = $infos['pharmacyCount']; 
      $pharmacyUpdated = $infos['pharmacyUpdated'];
      $gardCount = $infos['gardCount'];
      
      $result = last_scrape_info::create([
          'executedBy' =>  'App_single',
          'city' =>  'Casablanca',
          'guards_added' => $gardCount,
          'pharmacies_added' => $pharmacyCount,
          'pharmacies_Updated' => $pharmacyUpdated,
          'pharmacies_fails_count' =>  0,
          'updated_at' => Carbon::now()
      ]);

      if(Session()->has('pharmacyCount') && Session()->has('gardCount')) {
        Session()->pull('pharmacyCount');
        Session()->pull('gardCount');
      }
      Session()->put(['pharmacyCount' => $pharmacyCount, 'gardCount' => $gardCount]);
      
      if(Session()->has('pharmacyUpdated')) {
        Session()->pull('pharmacyUpdated');
      }
      Session()->put(['pharmacyUpdated' => $pharmacyUpdated]);
      
      return redirect()->route('dashboard');
    }

    public function scrapeRabat() {
      return self::scraper('https://www.annuaire-gratuit.ma/pharmacie-garde-rabat.html', 'Rabat', 2);
    }

    public function scrapeMarrakech() {
      return self::scraper("https://www.annuaire-gratuit.ma/pharmacie-garde-marrakech.html", 'Marrakech', 3);
    }

    public function scrapeEljadida() {
        return self::scraper('https://www.annuaire-gratuit.ma/pharmacie-garde-el-jadida.html', 'El Jadida', 4);
    }

    public function scrapeOujda() {
        return self::scraper('https://www.annuaire-gratuit.ma/pharmacie-garde-oujda.html', 'Oujda', 5);
    }

    public function scrapeMeknes() {
        return self::scraper('https://www.annuaire-gratuit.ma/pharmacie-garde-meknes.html', 'Meknes', 6);
    }

    public function scrapeAgadir() {
        return self::scraper('https://www.annuaire-gratuit.ma/pharmacie-garde-agadir.html', 'Agadir', 7);
    }

    public function scrapeSale() {
      return self::scraper('https://www.annuaire-gratuit.ma/pharmacie-garde-sale.html', 'Sale', 8);
    }

    public function scrapeSafi() {
        return self::scraper('https://www.annuaire-gratuit.ma/pharmacie-garde-safi.html', 'Safi', 9);
    }

    public function scrapeFes() {
        return self::scraper('https://www.annuaire-gratuit.ma/pharmacie-garde-fes.html', 'Fès', 10);
    }

    public function scrapeKenitra() {
        return self::scraper('https://www.annuaire-gratuit.ma/pharmacie-garde-kenitra.html', 'Kénitra', 11);
    }

    public function scrapeMohammedia() {
        return self::scraper('https://www.annuaire-gratuit.ma/pharmacie-garde-mohammedia.html', 'Mohammedia', 12);
    }

    public function scrapeTanger() {
        return self::scraper('https://www.annuaire-gratuit.ma/pharmacie-garde-tanger.html', 'Tanger', 13);
    }

    public function scrapeTemara() {
        return self::scraper('https://www.annuaire-gratuit.ma/pharmacie-garde-temara.html', 'Temara', 14);
    }
}
