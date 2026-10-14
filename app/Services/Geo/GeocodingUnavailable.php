<?php

namespace App\Services\Geo;

use RuntimeException;

/** OpenStreetMap could not be asked — as against "no such place". */
class GeocodingUnavailable extends RuntimeException {}
