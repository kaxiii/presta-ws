<?php
/**
 * functions/provinces.php
 *
 * Mapas de provincias / divisiones administrativas por país.
 * Países soportados: ES, IT, FR, PT, DE, NL, BE
 *
 * Uso:
 *   $map = provinces_get_map('ES');          // ['28' => 'Madrid', ...]
 *   $name = provinces_resolve_name('ES','28'); // "Madrid"
 */

if (!function_exists('provinces_get_map')) {
    function provinces_get_map(string $countryCode): array {
        switch (strtoupper($countryCode)) {

            // -------------------------
            // ESPAÑA (2 primeros dígitos CP)
            // -------------------------
            case 'ES':
                return [
                    "01"=>"Álava","02"=>"Albacete","03"=>"Alicante","04"=>"Almería","05"=>"Ávila","06"=>"Badajoz","07"=>"Illes Balears",
                    "08"=>"Barcelona","09"=>"Burgos","10"=>"Cáceres","11"=>"Cádiz","12"=>"Castellón","13"=>"Ciudad Real","14"=>"Córdoba",
                    "15"=>"A Coruña","16"=>"Cuenca","17"=>"Girona","18"=>"Granada","19"=>"Guadalajara","20"=>"Gipuzkoa","21"=>"Huelva",
                    "22"=>"Huesca","23"=>"Jaén","24"=>"León","25"=>"Lleida","26"=>"La Rioja","27"=>"Lugo","28"=>"Madrid","29"=>"Málaga",
                    "30"=>"Murcia","31"=>"Navarra","32"=>"Ourense","33"=>"Asturias","34"=>"Palencia","35"=>"Las Palmas","36"=>"Pontevedra",
                    "37"=>"Salamanca","38"=>"Santa Cruz de Tenerife","39"=>"Cantabria","40"=>"Segovia","41"=>"Sevilla","42"=>"Soria",
                    "43"=>"Tarragona","44"=>"Teruel","45"=>"Toledo","46"=>"Valencia","47"=>"Valladolid","48"=>"Bizkaia","49"=>"Zamora",
                    "50"=>"Zaragoza","51"=>"Ceuta","52"=>"Melilla"
                ];

            // -------------------------
            // ITALIA (siglas provincia)
            // -------------------------
            case 'IT':
                return [
                    "MI"=>"Milano","RM"=>"Roma","TO"=>"Torino","NA"=>"Napoli","FI"=>"Firenze","VE"=>"Venezia","BO"=>"Bologna",
                    "GE"=>"Genova","BA"=>"Bari","PA"=>"Palermo","CA"=>"Cagliari","VR"=>"Verona","BS"=>"Brescia","MO"=>"Modena",
                    "PR"=>"Parma","RE"=>"Reggio Emilia","PD"=>"Padova","TS"=>"Trieste","UD"=>"Udine","TN"=>"Trento"
                ];

            // -------------------------
            // FRANCIA (départements — 2 dígitos CP)
            // -------------------------
            case 'FR':
                return [
                    "75"=>"Paris","13"=>"Bouches-du-Rhône","69"=>"Rhône","59"=>"Nord","33"=>"Gironde","31"=>"Haute-Garonne",
                    "06"=>"Alpes-Maritimes","44"=>"Loire-Atlantique","67"=>"Bas-Rhin","34"=>"Hérault","83"=>"Var","38"=>"Isère",
                    "92"=>"Hauts-de-Seine","93"=>"Seine-Saint-Denis","94"=>"Val-de-Marne","77"=>"Seine-et-Marne"
                ];

            // -------------------------
            // PORTUGAL (distritos — 2 dígitos CP zona)
            // -------------------------
            case 'PT':
                return [
                    "10"=>"Lisboa","40"=>"Porto","30"=>"Coimbra","90"=>"Madeira","95"=>"Açores",
                    "47"=>"Braga","35"=>"Aveiro","50"=>"Viseu","60"=>"Castelo Branco","70"=>"Évora"
                ];

            // -------------------------
            // ALEMANIA (Bundesländer — códigos ISO cortos)
            // -------------------------
            case 'DE':
                return [
                    "BW"=>"Baden-Württemberg","BY"=>"Bayern","BE"=>"Berlin","BB"=>"Brandenburg","HB"=>"Bremen",
                    "HH"=>"Hamburg","HE"=>"Hessen","MV"=>"Mecklenburg-Vorpommern","NI"=>"Niedersachsen",
                    "NW"=>"Nordrhein-Westfalen","RP"=>"Rheinland-Pfalz","SL"=>"Saarland","SN"=>"Sachsen",
                    "ST"=>"Sachsen-Anhalt","SH"=>"Schleswig-Holstein","TH"=>"Thüringen"
                ];

            // -------------------------
            // PAÍSES BAJOS (provincias)
            // -------------------------
            case 'NL':
                return [
                    "DR"=>"Drenthe","FL"=>"Flevoland","FR"=>"Friesland","GE"=>"Gelderland","GR"=>"Groningen",
                    "LI"=>"Limburg","NB"=>"Noord-Brabant","NH"=>"Noord-Holland","OV"=>"Overijssel",
                    "UT"=>"Utrecht","ZE"=>"Zeeland","ZH"=>"Zuid-Holland"
                ];

            // -------------------------
            // BÉLGICA (regiones / provincias)
            // -------------------------
            case 'BE':
                return [
                    "VAN"=>"Antwerpen","VBR"=>"Vlaams-Brabant","VLI"=>"Limburg","VOV"=>"Oost-Vlaanderen","VWV"=>"West-Vlaanderen",
                    "WBR"=>"Brabant Wallon","WHT"=>"Hainaut","WLG"=>"Liège","WLX"=>"Luxembourg","WNA"=>"Namur",
                    "BRU"=>"Bruxelles-Capitale"
                ];

            default:
                return [];
        }
    }
}

if (!function_exists('provinces_resolve_name')) {
    function provinces_resolve_name(string $countryCode, string $provinceCode): string {
        $map = provinces_get_map($countryCode);
        $code = strtoupper(trim($provinceCode));
        return $map[$code] ?? $provinceCode;
    }
}
