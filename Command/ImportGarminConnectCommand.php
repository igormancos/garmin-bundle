<?php
namespace Site\GarminBundle\Command;

use Site\GarminBundle\Lib\GarminActivityInfo;
use Site\GarminBundle\Manager\GarminManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Buzz\Browser;
use Buzz\Client\Curl;
use Site\GarminBundle\Lib\GarminClient;

class ImportGarminConnectCommand extends ContainerAwareCommand
{
    /** @var GarminManager $manager */
    protected $manager;

    protected function configure()
    {
        $this->setName('garmin:import')
            ->setDescription('Import data from the Garmin Connect for selected users')
            ->addArgument('usename', InputArgument::REQUIRED, 'connect username')
            ->addArgument('password', InputArgument::REQUIRED, 'connect password');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->manager = $this->getContainer()->get('site.managers.garmin');

        $username = $input->getArgument('usename');
        $password = $input->getArgument('password');

        $browser = new Browser(new Curl());
        $client  = new GarminClient($browser);
        $client->signIn($username, $password);

        /** @var GarminActivityInfo[] $activities */
        $activities = $client->fetchActivities($username, 25);
        foreach ($activities as $activity) {
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid();
            $client->downloadActivity($activity->getId(), $tmp);
            $this->manager->import_effort(file_get_contents($tmp . '.gpx'));
        }

        $output->writeln('Imported successfully');
    }
}
