<?php
namespace Site\GarminBundle\Manager;

use Buzz\Browser;
use Buzz\Message\MessageInterface;
use Doctrine\ORM\EntityManager;
use GeoTools\LatLng;
use Site\ApiBundle\Geometry\BoundingBox;
use Site\ApiBundle\Helper\AttachmentHelper;
use Site\BaseBundle\Entity\User;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerAware;
use Doctrine\Bundle\DoctrineBundle\Registry;

use Site\BaseBundle\Entity\EffortRaw;
use Site\BaseBundle\Entity\Route;
use Site\BaseBundle\Entity\Repository\PointRawRepository;
use Site\BaseBundle\Entity\Repository\PointRepository;
use Site\BaseBundle\Entity\Repository\EdgeRouteRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Exception\ValidatorException;
use Symfony\Component\Validator\Validator;
use \DateTime;
use Site\ApiBundle\Geometry\Polyline;
use Site\ApiBundle\Geometry\Simplify;
use \Twig_Environment;
use Site\BaseBundle\Entity\PointRaw;
use Site\BaseBundle\Entity\Repository\EffortRawRepository;
use Site\GarminBundle\Lib\GarminActivityInfo;

/**
 * Class GarminManager
 *
 * @package Site\GarminBundle\Manager
 */
class GarminManager extends ContainerAware
{
    /** @var PointRawRepository $point_raw_repository */
    protected $point_raw_repository;
    /** @var EdgeRouteRepository $edge_route_repository */
    protected $edge_route_repository;
    /** @var PointRepository $point_repository */
    protected $point_repository;
    /** @var EntityManager $em */
    protected $em;
    /** @var Validator $validator */
    protected $validator;
    /** @var Twig_Environment $twig */
    protected $twig;
    /* @var EffortRawRepository $effort_repository */
    protected $effort_repository;

    /**
     * @param Registry         $doctrine
     * @param Validator        $validator
     * @param Twig_Environment $twig
     */
    public function __construct(Registry $doctrine, Validator $validator, Twig_Environment $twig)
    {
        $this->em                    = $doctrine->getManager();
        $this->point_raw_repository  = $doctrine->getRepository('SiteBaseBundle:PointRaw');
        $this->edge_route_repository = $doctrine->getRepository('SiteBaseBundle:EdgeRoute');
        $this->point_repository      = $doctrine->getRepository('SiteBaseBundle:Point');
        $this->effort_repository     = $doctrine->getRepository('SiteBaseBundle:EffortRaw');
        $this->validator             = $validator;
        $this->twig                  = $twig;
    }

    /**
     * Export the raw data as gpx format
     *
     * @param EffortRaw $effort
     * @param int       $simplify (Max coordinates number after simplify)
     *
     * @return string
     */

    /**
     * Export the raw data as gpx format
     *
     * @param EffortRaw $effort
     * @param bool      $simplify (Max coordinates number after simplify)
     *
     * @return string
     */
    public function export_effort(EffortRaw $effort, $simplify = false)
    {
        $points = array();
        if ($points_raw = $this->point_raw_repository->getPoints2Array($effort) and count($points_raw)) {
            $start_time = $effort->getStartDate()->format('c');
            foreach ($points_raw as $point) {
                $points[] = array(
                    $point['lat'],
                    $point['lng'],
                    $point['elevation'],
                    date('c', strtotime($start_time . ' +' . $point['time'] . ' seconds')),
                );
            }
        }

        if ($simplify) {
            $points = Simplify::simplifyUntil($points, $simplify);
        }

        $gpx = $this->twig->render(
            'GarminBundle::gpx.xml.twig',
            array(
                'object' => $effort,
                'points' => $points,
                'time'   => $effort->getStartDate()->format('c')
            )
        );

        // validate GPX file
        $xml = new \DOMDocument();
        $xml->loadXml($gpx);
        $xml->schemaValidate('http://www.topografix.com/GPX/1/1/gpx.xsd');

        return $gpx;
    }

    /**
     * Gets effort export response
     *
     * @param EffortRaw $effort
     *
     * @return Response
     */
    public function export_effort_response(EffortRaw $effort)
    {
        return $this->prepare_export_response($effort->getExportFilename(), $this->export_effort($effort));
    }

    /**
     * Export route to GPX file
     *
     * @param Route $route
     * @param bool  $simplify (Max coordinates number after simplify)
     *
     * @return string
     */
    public function export_route(Route $route, $simplify = false)
    {
        $points                = array();
        $edge_route_collection = $this->edge_route_repository->getEdgeRouteByRoute($route);
        $points_raw            = $this->point_repository->route2Points($edge_route_collection);

        // normalize point for simplify and twig template
        if (count($points_raw['latLng'])) {
            foreach ($points_raw['latLng'] as $k => $point) {
                $points[] = array(
                    $point[0],
                    $point[1],
                    isset($points_raw['ele'][$k]) ? $points_raw['ele'][$k] : 0,
                    isset($points_raw['time'][$k]) ? date('c', strtotime($route->getCreatedAt()->format('c') . ' +' . intval($points_raw['time'][$k]) . ' seconds')) : date('c'),
                );
            }
        }

        if ($simplify) {
            $points = Simplify::simplifyUntil($points, $simplify);
        }

        $gpx = $this->twig->render(
            'GarminBundle::gpx.xml.twig',
            array(
                'object' => $route,
                'points' => $points,
                'time'   => $route->getCreatedAt()->format('c')
            )
        );

        // validate GPX file
        $xml = new \DOMDocument();
        $xml->loadXml($gpx);
        $xml->schemaValidate('http://www.topografix.com/GPX/1/1/gpx.xsd');

        return $gpx;
    }

    /**
     * Exports segment/route
     *
     * @param Route $route
     *
     * @return Response
     */
    public function export_route_response(Route $route)
    {
        return $this->prepare_export_response($route->getExportFilename(), $this->export_route($route));
    }

    /**
     * Import data from TCX or GPX files
     *
     * @param        $file
     * @param int    $garminConnectId
     * @param        $user
     * @param string $routeName
     * @param string $garminName
     *
     * @throws \Symfony\Component\Validator\Exception\ValidatorException
     * @throws \Exception
     * @internal param $xml
     * @return EffortRaw                                                 $effort
     */
    public function import_effort($file, $garminConnectId = 0, $user, $routeName = '', $garminName = '')
    {
        /* @var \SimpleXMLElement $xml */
        $xml = new \SimpleXMLElement(file_exists($file) ? file_get_contents($file) : $file);

        $ride_info = $this->get_ride_info(file_exists($file) ? file_get_contents($file) : $file);

        if (empty($ride_info['coordinates'])) {
            throw new \Exception('Activity not imported due empty coordinates');
        }

        // GPX file (TRK)
        if (isset($xml->trk)) {
            // validate GPX file
            $validator = new \DOMDocument();
            $validator->loadXml(file_get_contents($file));
            $validator->schemaValidate('http://www.topografix.com/GPX/1/1/gpx.xsd');

            // metadata
            $startTime = isset($xml->metadata->time) ? new DateTime(date('c', strtotime($xml->metadata->time))) : new DateTime(date('c'));
            $maxlat    = isset($xml->metadata->bounds['maxlat']) ? $xml->metadata->bounds['maxlat'] : 0;
            $minlat    = isset($xml->metadata->bounds['minlat']) ? $xml->metadata->bounds['minlat'] : 0;
            $maxlon    = isset($xml->metadata->bounds['maxlon']) ? $xml->metadata->bounds['maxlon'] : 0;
            $minlon    = isset($xml->metadata->bounds['minlon']) ? $xml->metadata->bounds['minlon'] : 0;

            foreach ($xml->trk as $trk) {
                // track name
                $name = isset($ride_info['name']) ? $ride_info['name'] : 'Untitled';

                // create track in DB
                $effort = new EffortRaw();

                $effort
                    ->setUser($user)
                    ->setName($routeName ? : $name)
                    ->setStartDate($startTime)
                    ->setEndDate($startTime)
                    ->setGarminConnectId($garminConnectId ? : null)
                    ->setTime(0)
                    ->setLatMax($maxlat)
                    ->setLatMin($minlat)
                    ->setLngMax($maxlon)
                    ->setLngMin($minlon);

                $errors = $this->validator->validate($effort, array('Default', 'garmin_import'));
                if ($errors->count()) {
                    throw new ValidatorException($errors->get(0));
                }

                $this->em->beginTransaction();

                try {

                    // generate the latitude and longitude boundaries
                    $position = $trk->trkseg[0]->trkpt;
                    $bb = new BoundingBox(array(
                        (float)$position['lat'],
                        (float)$position['lat'],
                        (float)$position['lon'],
                        (float)$position['lon'],
                    ));

                    $this->em->persist($effort);
                    $this->em->flush();

                    foreach ($trk->trkseg as $trkseg) {
                        // skip empty Tracks
                        if ( !isset($trkseg->trkpt[0]['lat']) ) {
                            continue;
                        }
                        $points  = $this->point_raw_repository->insertXMLData($trkseg, $effort);
                        // generate the latitude and longitude boundaries
                        $bb->extend($points);
                        $latlngs = $this->getLatLngsFromXmlPoints($points);
                        /* THIRD SET OF QUERY: INSERT ALL THE SIMPLIFIED POINTS BELONGING TO THE EDGE */
                        foreach (PointRawRepository::$allowed_filters as $filter) {
                            $this->simplifyAndInsert($latlngs, $filter, $effort);
                        }
                        // get the end date from points and set for effort
                        $last_el = $points[count($points) - 1];
                        $effort->setEndDate(new DateTime(date('c', strtotime($last_el['date']))));
                        $effort->setTime($last_el['time']);
                        $this->container->get('site.managers.raw')->update_effort_distance($effort);

                        $this->em->persist($effort);
                        $this->em->flush();
                    }

                    // generate the latitude and longitude boundaries
                    $effort
                        ->setLatMin($bb->latMin())
                        ->setLatMax($bb->latMax())
                        ->setLngMin($bb->lngMin())
                        ->setLngMax($bb->lngMax())
                    ;
                    $this->em->persist($effort);
                    $this->em->flush();

                    $this->em->commit();

                    return $effort;
                } catch (\Exception $e) {
                    $this->em->rollback();
                    throw $e; // rethrow exception
                }
            }
        } // TCX file
        else {
            if (isset($xml->Activities)) {
                // validate GPX file
                $validator = new \DOMDocument();
                $validator->loadXml(file_get_contents($file));
                $validator->schemaValidate('http://www.garmin.com/xmlschemas/TrainingCenterDatabasev2.xsd');

                foreach ($xml->Activities->Activity as $activity) {
                    // track name (get with geocode)
                    $name = isset($ride_info['name']) ? $ride_info['name'] : 'Untitled';

                    $startTime = new DateTime($activity->Lap['StartTime']);
                    $endTime   = new DateTime(date('Y-m-d H:i:s', strtotime($startTime->format('Y-m-d H:i:s') . ' +' . (int) $activity->Lap->TotalTimeSeconds . ' seconds')));

                    // create track in DB
                    $effort = new EffortRaw();
                    $effort->setName($routeName ? : $name)
                        ->setUser($user)
                        ->setTime((int) $activity->Lap->TotalTimeSeconds)
                        ->setGarminConnectId($garminConnectId ? : null)
                        ->setGarminName($garminName)
                        ->setStartDate($startTime)
                        ->setEndDate($endTime)
                        ->setLatMax(0)
                        ->setLatMin(0)
                        ->setLngMax(0)
                        ->setLngMin(0);

                    $errors = $this->validator->validate($effort, array('Default', 'garmin_import'));
                    if ($errors->count()) {
                        throw new ValidatorException($errors->get(0));
                    }

                    $this->em->beginTransaction();

                    try {
                        $this->em->persist($effort);
                        $this->em->flush();

                        // generate the latitude and longitude boundaries
                        $position = $activity->Lap[0]->Track[0]->Trackpoint[0]->Position;
                        $bb = new BoundingBox(array(
                            (float)$position->LatitudeDegrees,
                            (float)$position->LatitudeDegrees,
                            (float)$position->LongitudeDegrees,
                            (float)$position->LongitudeDegrees,
                        ));

                        foreach ($activity->Lap as $lap) {
                            foreach ($lap->Track as $track) {
                                // skip empty Tracks
                                if ( !isset($track->Trackpoint[0]->Position[0]->LatitudeDegrees) ) {
                                    continue;
                                }
                                $points = $this->point_raw_repository->insertXMLData($track, $effort);
                                // generate the latitude and longitude boundaries
                                $bb->extend($points);
                                $latlngs = $this->getLatLngsFromXmlPoints($points);
                                /* THIRD SET OF QUERY: INSERT ALL THE SIMPLIFIED POINTS BELONGING TO THE EDGE */
                                foreach (PointRawRepository::$allowed_filters as $filter) {
                                    $this->simplifyAndInsert($latlngs, $filter, $effort);
                                }

                                $this->container->get('site.managers.raw')->update_effort_distance($effort);
                                $this->em->persist($effort);
                                $this->em->flush();
                            }
                        }

                        // generate the latitude and longitude boundaries
                        $effort
                            ->setLatMin($bb->latMin())
                            ->setLatMax($bb->latMax())
                            ->setLngMin($bb->lngMin())
                            ->setLngMax($bb->lngMax())
                        ;
                        $this->em->persist($effort);
                        $this->em->flush();

                        $this->em->commit();

                        return $effort;
                    } catch (\Exception $e) {
                        $this->em->rollback();
                        throw $e; // rethrow exception
                    }
                }
            }
        }

        throw new \Exception('File for import is wrong TCX / GPX file');
    }

    /**
     * Simplify points and insert in filter table
     *
     * @param                                   $points
     * @param                                   $filter
     * @param \Site\BaseBundle\Entity\EffortRaw $effort
     */
    public function simplifyAndInsert($points, $filter, EffortRaw $effort)
    {
        $this->point_raw_repository->insertFilterPoints($points, $filter, $effort);
    }

    /**
     * Return the ride information from TCX file
     *
     * @param string
     *
     * @return array (static map url, id, geocode name)
     */
    public function get_ride_info($string)
    {
        $coordinates = array();
        $xml         = new \SimpleXMLElement($string);

        if (isset($xml->Activities)) {
            foreach ($xml->Activities->Activity as $activity) {
                $id = (string) $activity->Id;
                foreach ($activity->Lap as $lap) {
                    $distance = round($lap->DistanceMeters);
                    $duration = round($lap->TotalTimeSeconds);
                    foreach ($lap->Track as $track) {
                        // skip empty Tracks
                        if ( !isset($track->Trackpoint[0]->Position[0]->LatitudeDegrees) ) {
                            continue;
                        }
                        foreach ($track->Trackpoint as $point) {
                            $coordinates[] = array(
                                number_format(floatval($point->Position->LatitudeDegrees), 4),
                                number_format(floatval($point->Position->LongitudeDegrees), 4)
                            );
                        }
                    }
                }
            }
        } else {
            if (isset($xml->trk)) {
                $id = (string) $xml->metadata->time;
                foreach ($xml->trk as $trk) {
                    foreach ($trk->trkseg as $trkseg) {
                        // skip empty Tracks
                        if ( !isset($trkseg->trkpt[0]['lat']) ) {
                            continue;
                        }
                        foreach ($trkseg->trkpt as $trkpt) {
                            $coordinates[] = array(
                                number_format(floatval($trkpt['lat']), 4),
                                number_format(floatval($trkpt['lon']), 4)
                            );
                        }
                    }
                }
            }
        }

        // simplify the very long ride
        if (count($coordinates) > 100) {
            $coordinates = Simplify::simplifyUntil($coordinates, 100);
        }

        // get the ride name from geocode
        if (count($coordinates)) {
            try {
                $browser = new Browser();
                $browser->get('http://maps.googleapis.com/maps/api/geocode/json?latlng=' . implode(',', $coordinates[0]) . '&sensor=false');
                /* @var MessageInterface $response */
                $response = $browser->getLastResponse();
                $result   = json_decode($response->getContent());
                if ($result->status == 'OK' && isset($result->results[0]->formatted_address)) {
                    $name = $result->results[0]->formatted_address;
                }
            } catch (\Exception $e) {
                $name = 'Unnamed';
            }
        }

        return array(
            'src'      => 'http://maps.googleapis.com/maps/api/staticmap?sensor=false&size=200x150&path=color:0x0000ffff|weight:2|enc:' . Polyline::Encode($coordinates),
            'id'       => isset($id) ? $id : '',
            'timestamp'=> isset($id) ? $id : '',
            'name'     => isset($name) ? $name : '',
            'distance' => isset($distance) ? $distance : 0,
            'duration' => isset($duration) ? $duration : 0,
            'coordinates'   => $coordinates,
        );
    }

    /**
     * Filter the Activities from Garmin Connect and return
     * only not imported
     */
    public function filter_garmin_connect_activities($activities, User $user)
    {
        $imported = $this->effort_repository->find_garmin_connect_activities($user);
        if (count($imported)) {
            /* @var GarminActivityInfo $activity */
            foreach ($activities as $i => $activity) {
                if (in_array($activity->getId(), $imported)) {
                    unset($activities[$i]);
                }
            }
        }

        return $activities;
    }

    /**
     * Prepares Response with content as attachment
     *
     * @param $filename
     * @param $content
     *
     * @return Response
     */
    protected function prepare_export_response($filename, $content)
    {
        $response = new Response();
        $response->headers->set('Content-type', 'text/xml');
        $response->setContent($content);
        AttachmentHelper::make_disposition_attachment($response, $filename);

        return $response;
    }

    /**
     * Converts array of result points got from garmin import - to array of LatLng
     *
     * @param array $points
     *
     * @return array
     */
    protected function getLatLngsFromXmlPoints(array $points)
    {
        return array_map(
            function ($e) {
                return new LatLng( $e[0], $e[1]);
            },
            $points
        );
    }
}
