<?php

declare(strict_types=1);

namespace App\Command;

use App\Shopify\TokenProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:shopify:token', description: 'Retrieves (and stores) a Shopify access token using the “Client Credentials Grant”')]
final class ShopifyTokenCommand extends Command
{
    public function __construct(private readonly TokenProvider $tokenProvider)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $token = $this->tokenProvider->getToken();

        $io->success('Token retrieved (shortened to 12 chars): ' . substr($token, 0, 12) . '...');

        return Command::SUCCESS;
    }
}
