<?php
namespace Site\GarminBundle\Lib;

use Endurance\GarminConnect\ActivityInfo;

/**
 * Class GarminActivityInfo
 *
 * @package Site\GarminBundle\Lib
 */
class GarminActivityInfo extends ActivityInfo
{
    protected $name;
    protected $startTimeGMT;
    protected $distance;
    protected $duration;

    /**
     * @param array $info
     */
    public function __construct(array $info)
    {
        parent::__construct($info);
        $this->name         = !empty($info['activityName']) ? $info['activityName'] : 'Untitled';
        $this->startTimeGMT = $info['startTimeGMT'] . 'Z';
        $this->distance     = round($info['distance']) ? : 0;
        $this->duration     = round($info['duration']) ? : 0;
    }

    public function getName()
    {
        return $this->name;
    }

    /**
     * @return mixed
     */
    public function getDistance()
    {
        if ($this->distance > 1000) {
            return (number_format($this->distance / 1000, 2, '.', '')) . ' km';
        } else {
            return $this->distance . ' m';
        }
    }

    /**
     * @return mixed
     */
    public function getDuration()
    {
        if ($this->duration >= 36000) {
            return round($this->duration / 3600) . date(' \h i \m\i\n s \s\e\c', $this->duration);
        } elseif ($this->duration >= 3600) {
            return date('h \h i \m\i\n s \s\e\c', $this->duration);
        } else {
            if ($this->duration < 60) {
                return date('s \s\e\c', $this->duration);
            } else {
                return date('i \m\i\n s \s\e\c', $this->duration);
            }
        }
    }

    /**
     * @return mixed
     */
    public function getStartTimeGMT()
    {
        return $this->startTimeGMT;
    }
}
