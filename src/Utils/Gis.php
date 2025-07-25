<?php

declare(strict_types=1);

namespace PhpMyAdmin\Utils;

use PhpMyAdmin\Dbal\DatabaseInterface;
use Twig\Attribute\AsTwigFunction;

use function array_map;
use function bin2hex;
use function mb_strtolower;
use function mb_strtoupper;
use function preg_match;
use function trim;

final class Gis
{
    /**
     * Converts GIS data to Well Known Text format
     *
     * @param string $data        GIS data
     * @param bool   $includeSRID Add SRID to the WKT
     *
     * @return string GIS data in Well Know Text format
     */
    public static function convertToWellKnownText(string $data, bool $includeSRID = false): string
    {
        // Convert to WKT format
        $hex = bin2hex($data);
        $spatialAsText = 'ASTEXT';
        $spatialSrid = 'SRID';
        $axisOrder = '';
        $dbi = DatabaseInterface::getInstance();
        $mysqlVersionInt = $dbi->getVersion();
        if ($mysqlVersionInt >= 50600) {
            $spatialAsText = 'ST_ASTEXT';
            $spatialSrid = 'ST_SRID';
        }

        if ($mysqlVersionInt >= 80001 && ! $dbi->isMariaDB()) {
            $axisOrder = ', \'axis-order=long-lat\'';
        }

        $wktsql = 'SELECT ' . $spatialAsText . "(x'" . $hex . "'" . $axisOrder . ')';
        if ($includeSRID) {
            $wktsql .= ', ' . $spatialSrid . "(x'" . $hex . "')";
        }

        $wktresult = $dbi->tryQuery($wktsql);
        $wktarr = [];
        if ($wktresult) {
            $wktarr = $wktresult->fetchRow();
        }

        $wktval = $wktarr[0] ?? '';

        if ($includeSRID) {
            $srid = $wktarr[1] ?? null;
            $wktval = "'" . $wktval . "'," . $srid;
        }

        return $wktval;
    }

    /**
     * Return GIS data types
     *
     * @param bool $upperCase whether to return values in upper case
     *
     * @return string[] GIS data types
     */
    #[AsTwigFunction('get_gis_datatypes')]
    public static function getDataTypes(bool $upperCase = false): array
    {
        $gisDataTypes = [
            'geometry',
            'point',
            'linestring',
            'polygon',
            'multipoint',
            'multilinestring',
            'multipolygon',
            'geometrycollection',
        ];
        if ($upperCase) {
            return array_map(mb_strtoupper(...), $gisDataTypes);
        }

        return $gisDataTypes;
    }

    /**
     * Generates GIS data based on the string passed.
     *
     * @param string $gisString    GIS string
     * @param int    $mysqlVersion The mysql version as int
     *
     * @return string GIS data enclosed in 'ST_GeomFromText' or 'GeomFromText' function
     */
    public static function createData(string $gisString, int $mysqlVersion): string
    {
        $geomFromText = $mysqlVersion >= 50600 ? 'ST_GeomFromText' : 'GeomFromText';
        $gisString = trim($gisString);
        $geomTypes = '(POINT|MULTIPOINT|LINESTRING|MULTILINESTRING|POLYGON|MULTIPOLYGON|GEOMETRYCOLLECTION)';
        if (preg_match("/^'" . $geomTypes . "\(.*\)',[0-9]*$/i", $gisString) === 1) {
            return $geomFromText . '(' . $gisString . ')';
        }

        if (preg_match('/^' . $geomTypes . '\(.*\)$/i', $gisString) === 1) {
            return $geomFromText . "('" . $gisString . "')";
        }

        return $gisString;
    }

    /**
     * Returns the names and details of the functions
     * that can be applied on geometry data types.
     *
     * @param string|null $geomType if provided the output is limited to the functions
     *                          that are applicable to the provided geometry type.
     * @param bool        $binary   if set to false functions that take two geometries
     *                              as arguments will not be included.
     * @param bool        $display  if set to true separators will be added to the
     *                              output array.
     *
     * @return array<int|string,array<string,int|string>> names and details of the functions that can be applied on
     *                                                    geometry data types.
     */
    #[AsTwigFunction('get_gis_functions')]
    public static function getFunctions(
        string|null $geomType = null,
        bool $binary = true,
        bool $display = false,
    ): array {
        $funcs = [];
        if ($display) {
            $funcs[] = ['display' => ' '];
        }

        // Unary functions common to all geometry types
        $funcs['Dimension'] = ['params' => 1, 'type' => 'int'];
        $funcs['Envelope'] = ['params' => 1, 'type' => 'Polygon'];
        $funcs['GeometryType'] = ['params' => 1, 'type' => 'text'];
        $funcs['SRID'] = ['params' => 1, 'type' => 'int'];
        $funcs['IsEmpty'] = ['params' => 1, 'type' => 'int'];
        $funcs['IsSimple'] = ['params' => 1, 'type' => 'int'];

        $geomType = mb_strtolower(trim((string) $geomType));
        if ($display && $geomType !== 'geometry' && $geomType !== 'multipoint') {
            $funcs[] = ['display' => '--------'];
        }

        $spatialPrefix = '';
        if (DatabaseInterface::getInstance()->getVersion() >= 50601) {
            // If MySQL version is greater than or equal 5.6.1,
            // use the ST_ prefix.
            $spatialPrefix = 'ST_';
        }

        // Unary functions that are specific to each geometry type
        if ($geomType === 'point') {
            $funcs[$spatialPrefix . 'X'] = ['params' => 1, 'type' => 'float'];
            $funcs[$spatialPrefix . 'Y'] = ['params' => 1, 'type' => 'float'];
        } elseif ($geomType === 'linestring') {
            $funcs['EndPoint'] = ['params' => 1, 'type' => 'point'];
            $funcs['GLength'] = ['params' => 1, 'type' => 'float'];
            $funcs['NumPoints'] = ['params' => 1, 'type' => 'int'];
            $funcs['StartPoint'] = ['params' => 1, 'type' => 'point'];
            $funcs['IsRing'] = ['params' => 1, 'type' => 'int'];
        } elseif ($geomType === 'multilinestring') {
            $funcs['GLength'] = ['params' => 1, 'type' => 'float'];
            $funcs['IsClosed'] = ['params' => 1, 'type' => 'int'];
        } elseif ($geomType === 'polygon') {
            $funcs['Area'] = ['params' => 1, 'type' => 'float'];
            $funcs['ExteriorRing'] = ['params' => 1, 'type' => 'linestring'];
            $funcs['NumInteriorRings'] = ['params' => 1, 'type' => 'int'];
        } elseif ($geomType === 'multipolygon') {
            $funcs['Area'] = ['params' => 1, 'type' => 'float'];
            $funcs['Centroid'] = ['params' => 1, 'type' => 'point'];
            // Not yet implemented in MySQL
            //$funcs['PointOnSurface'] = array('params' => 1, 'type' => 'point');
        } elseif ($geomType === 'geometrycollection') {
            $funcs['NumGeometries'] = ['params' => 1, 'type' => 'int'];
        }

        // If we are asked for binary functions as well
        if ($binary) {
            // section separator
            if ($display) {
                $funcs[] = ['display' => '--------'];
            }

            $funcs[$spatialPrefix . 'Crosses'] = ['params' => 2, 'type' => 'int'];
            $funcs[$spatialPrefix . 'Contains'] = ['params' => 2, 'type' => 'int'];
            $funcs[$spatialPrefix . 'Disjoint'] = ['params' => 2, 'type' => 'int'];
            $funcs[$spatialPrefix . 'Equals'] = ['params' => 2, 'type' => 'int'];
            $funcs[$spatialPrefix . 'Intersects'] = ['params' => 2, 'type' => 'int'];
            $funcs[$spatialPrefix . 'Overlaps'] = ['params' => 2, 'type' => 'int'];
            $funcs[$spatialPrefix . 'Touches'] = ['params' => 2, 'type' => 'int'];
            $funcs[$spatialPrefix . 'Within'] = ['params' => 2, 'type' => 'int'];

            if ($display) {
                $funcs[] = ['display' => '--------'];
            }

            // Minimum bounding rectangle functions
            $funcs['MBRContains'] = ['params' => 2, 'type' => 'int'];
            $funcs['MBRDisjoint'] = ['params' => 2, 'type' => 'int'];
            $funcs['MBREquals'] = ['params' => 2, 'type' => 'int'];
            $funcs['MBRIntersects'] = ['params' => 2, 'type' => 'int'];
            $funcs['MBROverlaps'] = ['params' => 2, 'type' => 'int'];
            $funcs['MBRTouches'] = ['params' => 2, 'type' => 'int'];
            $funcs['MBRWithin'] = ['params' => 2, 'type' => 'int'];
        }

        return $funcs;
    }
}
