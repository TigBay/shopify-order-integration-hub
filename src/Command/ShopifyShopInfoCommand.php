<?php

declare(strict_types=1);

namespace App\Command;

use App\Shopify\GraphqlClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:shopify:shop-info', description: 'Fragt Name und Währung des Shops über die GraphQL Admin API ab')]
final class ShopifyShopInfoCommand extends Command
{
    public function __construct(private readonly GraphqlClient $graphqlClient)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $data = $this->graphqlClient->query('{ shop { name currencyCode } }');

        $io->success(sprintf('Shop: %s (currency: %s)', $data['shop']['name'], $data['shop']['currencyCode']));

        return Command::SUCCESS;
    }
}
