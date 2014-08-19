<?php
namespace Site\GarminBundle\Lib;

use Endurance\GarminConnect\GarminConnectClient;

class GarminClient extends GarminConnectClient
{
    /**
     * @param        $id
     * @param        $file
     * @param string $type (gpx | tcx)
     *
     * @throws \RuntimeException
     */
    public function downloadActivity($id, $file, $type = 'gpx')
    {
        if (!$this->isSignedIn()) {
            throw new \RuntimeException('Not signed in');
        }

        $response = $this->browser->get('http://connect.garmin.com/proxy/activity-service-1.1/gpx/activity/' . $id . '?full=true');
        file_put_contents($file . '.' . $type, $response->getContent());
    }

    public function fetchActivities($username = null, $limit = 50, $start = 1)
    {
        if (!$this->isSignedIn()) {
            throw new \RuntimeException('Not signed in');
        }

        if ($username === null) {
            // Default to the signed in user
            $username = $this->username;
        }

        $response = $this->browser->get('http://connect.garmin.com/proxy/activitylist-service/activities/' . $username . '?limit=' . $limit . '&start=' . $start);
        $result   = json_decode($response->getContent(), true);

        return array_map(
            function ($info) {
                return new GarminActivityInfo($info);
            },
            $result['activityList']
        );
    }
}
