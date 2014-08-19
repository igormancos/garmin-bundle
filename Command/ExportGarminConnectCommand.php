<?php
namespace Site\GarminBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Buzz\Browser;
use Buzz\Client\Curl;
use Site\GarminBundle\Lib\GarminClient;

class ExportGarminConnectCommand extends ContainerAwareCommand
{
    /** @var GarminManager $manager */
    protected $manager;

    protected function configure()
    {
        $this->setName('garmin:export')
            ->setDescription('Export data to the Garmin Connect for selected users')
            ->addArgument('usename', InputArgument::REQUIRED, 'connect username')
            ->addArgument('password', InputArgument::REQUIRED, 'connect password');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->manager = $this->getContainer()->get('site.managers.garmin');
        $repository    = $this->getContainer()->get('doctrine')->getRepository('SiteBaseBundle:EffortRaw');

        $username = $input->getArgument('usename');
        $password = $input->getArgument('password');

        $browser = new Browser(new Curl());
        $client  = new GarminClient($browser);
        $client->signIn($username, $password);

        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid();

        file_put_contents($tmp, $this->manager->export_effort($repository->find(1)));

        $result = $client->uploadActivity($tmp);
        if (isset($result['detailedImportResult']['successes']) and count($result['detailedImportResult']['successes'])) {
            $output->writeln('Exported with ID ' . $result['detailedImportResult']['successes'][0]['internalId']);
        }

    }
}
