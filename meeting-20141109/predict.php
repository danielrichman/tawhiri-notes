<?php
  include_once('config.php');
  
  // Connect to the database
    $connection = pg_connect("dbname=spacenear");

  /////////////////////////////////////////////////////////////////////////////
  function proc_exec($cmd, $cwd, $stdin, &$stdout, &$stderr) { 
  /////////////////////////////////////////////////////////////////////////////
    $descriptorspec = array(0 => array("pipe", "r"),  // stdin
                            1 => array("pipe", "w"),   // stdout
                            2 => array("pipe", "w"));  // stderr
    $pipes = array();
    $process = proc_open($cmd, $descriptorspec, $pipes, $cwd);

    $proc_start = time();
    $exitcode = null;
    $ok = true;

    if(!is_resource($process)) {
      echo "Failed to open process";
      return null;
    }

    stream_set_blocking($pipes[1], 0);
    stream_set_blocking($pipes[2], 0);

    fwrite($pipes[0], $stdin);
    fflush($pipes[0]);
    fclose($pipes[0]);

    while(1) {
      $read = array();
      if(!feof($pipes[1])) $read[] = $pipes[1];
      if(!feof($pipes[2])) $read[] = $pipes[2];
      
      if(empty($read)) break;

      $ready = stream_select($read, $write = NULL, $ex = NULL, 2);
      
      if($ready === false) break;

      foreach($read as $r) {
        if($r == $pipes[1]) {
          $stdout .= fread($r, 1024);
        } else {
          $data = fread($r, 1024);
          if (strlen($stderr) < 1024 * 1024)
            $stderr .= $data;
        }
      }

      $stat = proc_get_status($process);
      if (time() - $proc_start > 6 && $stat["running"]) {
        error_log("WARN: Predictor didn't finish in 5 seconds, terminating");
        proc_terminate($process);
        $ok = false;
        break;
      } else if (!$stat["running"] && $exitcode === null) {
        $exitcode = $stat["exitcode"];
        break;
      }
    }

    fclose($pipes[1]);
    fclose($pipes[2]);

    if ($exitcode === null) {
      $exitcode = proc_close($process);
    } else {
      proc_close($process);
    }
    if ($exitcode !== 0) {
      error_log("Predictor exited with code $exitcode");
      $ok = false;
    }

    return $ok;
  }

  /////////////////////////////////////////////////////////////////////////////
  function get_density($h) {
  /////////////////////////////////////////////////////////////////////////////
    $p0 = 101325;   // se level standard atrmospheric pressure (Pa)
    $T0 = 288.15;   // sea level standard temperature (K)
    $g = 9.80665;   // earth-surface gravitational acceleration (m/s^2)
    $L = 0.0065;    // temperature lapse rate (K/m)
    $R = 8.31447;   // universal gas constant (J/mol/K)
    $M = 0.0289644; // molar mass of dry air (kg/mol)

    $T = $T0 - $L * $h; // temperature at altitude h (only valid inside troposphere...)

    $p = $p0 * pow(1.0 - $L * $h / $T, $g * $M / ($R * $L)); // pressure at altitude h

    $rho = $p * $M / ($R * $T); // density at altitude h (kg/m^3)

    return $rho;
  }

  /////////////////////////////////////////////////////////////////////////////
  function get_density2($altitude, $deltaTemperature = 0.0) {
  /////////////////////////////////////////////////////////////////////////////
    $airMolWeight     = 28.9644;  // Molecular weight of air
    $densitySL        = 1.225;    // Density at sea level [kg/m3]
    $pressureSL       = 101325;   // Pressure at sea level [Pa]
    $temperatureSL    = 288.15;   // Temperature at sea level [deg K]
    $gamma            = 1.4;
    $gravity          = 9.80665;  // Acceleration of gravity [m/s2]
    $tempGrad         = -0.0065;  // Temperature gradient [deg K/m]
    $RGas             = 8.31432;  // Gas constant [kg/Mol/K]
    $R                = 287.053;  //

    $altitudes    = array(0, 11000, 20000, 32000, 47000, 51000, 71000, 84852);
    $pressureRels = array(1, 2.23361105092158e-1, 5.403295010784876e-2,
                          8.566678359291667e-3, 1.0945601337771144e-3, 6.606353132858367e-4,
                          3.904683373343926e-5, 3.6850095235747942e-6);
    $temperatures = array(288.15, 216.65, 216.65, 228.65, 270.65, 270.65, 214.65, 186.946);
    $tempGrads    = array(-6.5, 0, 1, 2.8, 0, -2.8, -2, 0);
    $gMR = $gravity * $airMolWeight / $RGas;

    $i = 0;
    if($altitude > 0) {
      while($altitude > $altitudes[$i+1]) $i = $i + 1; 
    }

    $baseTemp        = $temperatures[$i];
    $tempGrad        = $tempGrads[$i] / 1000;
    $pressureRelBase = $pressureRels[$i];
    $deltaAltitude   = $altitude - $altitudes[$i];
    $temperature     = $baseTemp + $tempGrad * $deltaAltitude;

    // Calculate relative pressure
    if(abs($tempGrad) < 1e-10) {
      $pressureRel = $pressureRelBase * exp(-$gMR * $deltaAltitude / 1000.0 / $baseTemp);
    } else {
      $pressureRel = $pressureRelBase * pow($baseTemp / $temperature, $gMR / $tempGrad / 1000.0);
    }
  
    // Add temperature offset
    $temperature  = $temperature + $deltaTemperature;

    $speedOfSound = sqrt($gamma * $R * $temperature);
    $pressure     = $pressureRel * $pressureSL;
    $density      = $densitySL * $pressureRel * $temperatureSL / $temperature ;

    return $density;
  }  

  /////////////////////////////////////////////////////////////////////////////
  function sea_level_descent_rate($v, $h) {
  /////////////////////////////////////////////////////////////////////////////
    $rho = get_density2($h);
    return sqrt(($rho / 1.22) * pow($v, 2));
  }

  function find_dataset($root) {
    #$dir = $root."/tawhiri/datasets";
    $dir = $root;
    $files = scandir($dir, 1);
    foreach ($files as $file) {
      $p = strptime($file, "%Y%m%d%H");
      if (!$p || $p["unparsed"])
        continue;

      $ts = gmmktime($p["tm_hour"], 0, 0, $p["tm_mon"] + 1, $p["tm_mday"], $p["tm_year"] + 1900);
      assert($ts);

      return array($dir."/".$file, $ts);
    }
    return null;
  }

  /////////////////////////////////////////////////////////////////////////////
  function get_prediction($lat, $lon, $alt, $timestamp, $ascent_rate, $descent_rate, $burst_alt, $descending) {
  /////////////////////////////////////////////////////////////////////////////
    /*
    $lat = 52.2135;
    $lon = 0.0964;
    $alt = 200;
    $timestamp = 1267488000;
    $ascent_rate = 3;
    $descent_rate = 5;
    $burst_alt = 30000;
    $descending = false;
    */

    date_default_timezone_set('UTC');
    //$timestamp -= 60*60*9.5; // PROJECT HORUS, TAKE THIS OUT LATER!!!!

    $cwd = "/var/www/predict";
    $newdataset_dir = "/srv/tawhiri-datasets";
    $pred_binary = $cwd."/pred_src/pred";
    $dataset = find_dataset($newdataset_dir);
    if (!$dataset) {
      error_log("Couldn't find any dataset to use for live prediction");
      return json_encode(array("errors" => array("Couldn't find a dataset"), "warnings" => array()));
    }

    $cmd = "$pred_binary  -i ${dataset[0]} -s ${dataset[1]} " . ($descending ? " -d" : "");
  
    // Typical RMS values for windspeed error can be found from
    // http://www.emc.ncep.noaa.gov/gmb/STATS/html/rmsve82.html

    $ini  = "[launch-site]\n"
          . "latitude   = $lat\n" // degrees
          . "longitude  = $lon\n" // degrees
          . "altitude   = $alt\n" // meters
          . "[launch-time]\n"
          . "year       = " . gmdate("Y", $timestamp) . "\n"
          . "month      = " . gmdate("n", $timestamp) . "\n"
          . "day        = " . gmdate("j", $timestamp) . "\n"
          . "hour       = " . gmdate("G", $timestamp) . "\n"
          . "minute     = " . ((int) gmdate("i", $timestamp)) . "\n"
          . "second     = " . ((int) gmdate("s", $timestamp)) . "\n"
          . "[atmosphere]\n"
          . "wind-error     = 0\n" // m/s - RMS error for windspeed
          . "[altitude-model]\n"
          . "ascent-rate    = $ascent_rate\n"   // m/s
          . "descent-rate   = $descent_rate\n"  // m/s at sea level
          . "burst-altitude = $burst_alt\n"     // m
          . "float-time     = 0\n"; // s - float time at apogee (not implemented)
    
    //echo $ini;
    
    $proc_ok = proc_exec($cmd, $cwd, $ini, $stdout, $stderr);

    //echo "<pre>$stdout</pre>";
    //echo "<pre>$stderr</pre>";

    if (!$proc_ok) {
      return json_encode(array("errors" => array("Process timed out"), "warnings" => array()));
    }

    $errors = array();
    foreach (array("ERROR", "Assertion") as $bad_word) {
      $where_derp = strpos($stderr, $bad_word);
      if ($where_derp !== false)
        $errors[] = $where_derp;
    }

    if(count($errors)) {
      $first_error = min($errors);
      $end_of_line = strpos($stderr, "\n", $first_error);
      $derezzed = substr($stderr, $first_error, $end_of_line - $first_error);
      $fall = array("warnings" => array(), "errors" => array($derezzed));
      error_log("prediction failed: " . $derezzed);
      return json_encode($fall);
    }

    $points = array();
    
    // add current location
    $points[] = array("time" => $timestamp,
                      "lat" => $lat,
                      "lon" => $lon,
                      "alt" => $alt);
                      
    $num_points = preg_match_all('/([^,]*),([^,]*),([^,]*),(.*)/', $stdout, $matches, PREG_SET_ORDER);
    if($num_points > 0) {
      foreach($matches as $p) {
        $points[] = array("time" => trim($p[1]),
                          "lat" => trim($p[2]),
                          "lon" => trim($p[3]),
                          "alt" => trim($p[4]));
      }
    }
    return json_encode($points);
  }
  
  /////////////////////////////////////////////////////////////////////////////
  function get_stats($mission_id, $vehicle) {
  /////////////////////////////////////////////////////////////////////////////
    date_default_timezone_set('UTC');
    
    $positions_query = pg_query("SELECT extract('epoch' FROM gps_time)::int as gps_time, gps_lat, gps_lon, gps_alt, sequence FROM positions WHERE mission_id = '$mission_id' AND vehicle = '".pg_escape_string($vehicle)."' AND picture = '' ORDER BY positions.gps_time, sequence");
    
    $curr = 0;
    $window = 5;
    $sample = array();
    $count = 0;
    $prev_sequence = 0;
    $prev_time = 0;
    
    $rate = 0;
    $altitude = 0;
    $sample_altitude = 0; // average altitude over the sample window
    $max_rate = -PHP_INT_MAX;
    $min_rate = PHP_INT_MAX;
    $max_altitude = -PHP_INT_MAX;
    $min_altitude = PHP_INT_MAX;
    $curr_position = null;
    while($position = pg_fetch_assoc($positions_query)) {
      if($prev_sequence == $position["sequence"] && $prev_sequence != 0) {
        continue; // skip same sequence numbers
      } else {
        $prev_sequence = $position["sequence"];
      }
      if($prev_time == $position["gps_time"] && $prev_time != 0) {
        continue; // skip same times
      } else {
        $prev_time = $position["gps_time"];
      }
      $sample[$curr] = array("time" => $position["gps_time"], "altitude" => $position["gps_alt"]);
      $curr = ($curr + 1) % $window;
      $count++;
      
      // if we have one complete sample, calculate the rate
      if($count >= $window) {
        $rate = 0;
        $prev_s = $sample[$curr];
        for($i = 1; $i < $window; $i++) {
          $curr_s = $sample[($curr + $i) % $window];
          $delta_alt = $curr_s["altitude"] - $prev_s["altitude"];
          $delta_time = $curr_s["time"] - $prev_s["time"];
          $rate += $delta_alt / $delta_time;
          $prev_s = $curr_s;
        }
        $rate /= $window;
        
        $min_rate = min($rate, $min_rate);
        $max_rate = max($rate, $max_rate);

        $a1 = $sample[$curr]["altitude"];
        $a2 = $sample[($curr + $window - 1) % $window]["altitude"];
        $sample_altitude = ($a1 + $a2) / 2;
      } else {
        $sample_altitude = $position["gps_alt"];
      }
      
      $altitude = $position["gps_alt"];
      $min_altitude = min($altitude, $min_altitude);
      $max_altitude = max($altitude, $max_altitude);
      
      $curr_position = $position;
      //printf("Rate: $rate\n");
    }
    
    return array("rate" => $rate,
                 "max_rate" => $max_rate,
                 "min_rate" => $min_rate,
                 "altitude" => $altitude,
                 "max_altitude" => $max_altitude,
                 "min_altitude" => $min_altitude,
                 "sample_altitude" => $sample_altitude,
                 "latitude" => $curr_position["gps_lat"],
                 "longitude" => $curr_position["gps_lon"],
                 "time" => $curr_position["gps_time"]);
  }
  
  /////////////////////////////////////////////////////////////////////////////
  function update_prediction($mission_id, $vehicle, $force_descent = false, $force_predict = false) {
  /////////////////////////////////////////////////////////////////////////////
    global $default_burst_alt, $default_ascent_rate, $default_descent_rate;
    global $debug, $throttle_predictions, $per_vehicle_prediction;
  
    if ($force_descent)
      $force_predict = true;

    // Load values for per-mission settings if they exist
    if(is_array($per_vehicle_prediction) &&
       is_array($per_vehicle_prediction[$vehicle]))
    {
      $p = $per_vehicle_prediction[$vehicle];
      if(isset($p['burst_alt']))    $default_burst_alt    = $p['burst_alt'];
      if(isset($p['ascent_rate']))  $default_ascent_rate  = $p['ascent_rate'];
      if(isset($p['descent_rate'])) $default_descent_rate = $p['descent_rate'];
    }

    /* djr61: this lock used to just wrap the select/delete/insert sequence below.
     * However, the 'get_stats' call is /really/ slow, and since all the track.php hits for a new
     * string arrive pretty much simultaneuosly, they'll all race past the throttle below and
     * all call get_stats simultaneously.
     * This means that the LOCK TABLE statement will take the same time as a get_stats call
     * for those that lose the race. This is a shame; a lot of wasted time; but it's a strict
     * improvement on before (and that wasted time is spent idle, crucially).
     * If someone wants to do better locking (say, advisory locking, with vehicle granularity
     * and then if you can't take the lock, return) then please do. */
    pg_query("BEGIN");
    pg_query("LOCK TABLE predictions");

    // check if we already calculated prediction
    if(!$force_predict) {
      $positions_query = pg_query("SELECT extract('epoch' FROM gps_time)::int as time, gps_lat as latitude, gps_lon as longitude, gps_alt as altitude FROM positions WHERE mission_id = '$mission_id' AND vehicle = '".pg_escape_string($vehicle)."' AND picture = '' ORDER BY gps_time DESC, sequence DESC, position_id DESC LIMIT 1");
      $num_rows = pg_num_rows($positions_query);
      if($num_rows >= 1) {
        $position = pg_fetch_assoc($positions_query);
        $prediction_query = pg_query("SELECT extract('epoch' FROM time)::int as time, latitude, longitude, altitude FROM predictions WHERE mission_id = '$mission_id' AND vehicle = '$vehicle'");
        $num_rows = pg_num_rows($prediction_query);
        if($num_rows >= 1) {
          $prediction = pg_fetch_assoc($prediction_query);
          $pred_age = abs($position["time"] - $prediction["time"]);

          if ($debug)
            print "Current prediction age: $pred_age (".$position["time"]." - ".$prediction["time"].")\n";

          if(isset($throttle_predictions) && $pred_age < $throttle_predictions) {
            if ($debug)
              print "Prediction throttled: age < $throttle_predictions\n";

            pg_query("COMMIT");
            return;
          }
        }
      }
    }
    
    if ($debug)
      print "Updating prediction...\n";

    $stats = get_stats($mission_id, $vehicle);
    
    //print_r($stats);
    
    $descending = $stats["rate"] != 0
                  && $stats["altitude"] < $stats["max_altitude"]
                  && $stats["max_altitude"] > 1000
                  && $stats["min_rate"] < -5.0; 
    
    if($force_descent) {              
      $descending = true;
    }

    $landed = $stats["max_altitude"] > 1000
              && $stats["altitude"] < 300
              && $stats["rate"] < 1.0;
    
    if(!$descending) {
      $ascent_rate = $stats["rate"] != 0 ? $stats["rate"] : $default_ascent_rate;
      if($ascent_rate < 0) $ascent_rate = $default_ascent_rate;
      $descent_rate = $default_descent_rate;
      $burst_alt = max($default_burst_alt, $stats["altitude"]);
    } else {
      $ascent_rate = $default_ascent_rate;
      $descent_rate = -$stats["rate"];
      if($descent_rate < 0) {
        $descent_rate = $default_descent_rate;
      } else {
        //$descent_rate = ($descent_rate + sea_level_descent_rate($descent_rate, $stats["sample_altitude"])) / 2; 
        $descent_rate = sea_level_descent_rate($descent_rate, $stats["sample_altitude"]);
      }
      $burst_alt = $stats["max_altitude"];
    }

    $json = get_prediction($stats["latitude"],
                           $stats["longitude"],
                           $stats["altitude"],
                           $stats["time"],
                           $ascent_rate,
                           $descent_rate,
                           $burst_alt,
                           $descending);
    
    //echo $json;

    $prediction_id = "DEFAULT";
    $prediction_query = pg_query("SELECT prediction_id FROM predictions WHERE mission_id = '$mission_id' AND vehicle = '".pg_escape_string($vehicle)."'");
    $num_rows = pg_num_rows($prediction_query);
    if($num_rows >= 1) {
      $prediction_id = pg_fetch_row($prediction_query);
      $prediction_id = (int) $prediction_id[0];
    } 

    if ($prediction_id != "DEFAULT")
      pg_query("DELETE FROM predictions WHERE prediction_id=$prediction_id");

    $prediction_query = pg_query("INSERT INTO predictions (prediction_id, mission_id, vehicle, time, latitude, longitude, altitude, ascent_rate, descent_rate, burst_altitude, descending, landed, data) VALUES ($prediction_id, '$mission_id', '".pg_escape_string($vehicle)."', (TIMESTAMP WITH TIME ZONE 'epoch' + (INTERVAL '1 second' * ".$stats["time"].")), '".$stats["latitude"]."', '".$stats["longitude"]."', '".$stats["altitude"]."', '$ascent_rate', '$descent_rate', '$burst_alt', '".($descending? "true" : "false")."', '".($landed? "true" : "false")."', '".pg_escape_string($json)."')");
     pg_query("COMMIT");

    if($debug) {
      echo htmlspecialchars($vehicle);
      if($landed) {
        echo " landed\n";
      } else if($descending) {
        echo " descending\n";
      } else {
        echo " ascending\n";
      }
      if(!$descending)
        echo "Ascent Rate: $ascent_rate\n";
      if(!$landed)
        echo "Descent Rate: $descent_rate\n";
      
      print "Done, saved prediction (".$stats["time"].")\n";
    }

  }
?>
