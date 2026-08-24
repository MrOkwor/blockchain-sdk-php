<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Blockchain Network
    |--------------------------------------------------------------------------
    |
    | The default blockchain driver to use when none is explicitly specified.
    |
    */
    'default' => env('BLOCKCHAIN_DEFAULT_NETWORK', 'ethereum'),

    /*
    |--------------------------------------------------------------------------
    | Master Cold Vault Wallets (Deposit Sweep Destination)
    |--------------------------------------------------------------------------
    |
    | Public receiving addresses for your central master cold vaults. All customer
    | sub-wallet balances will be consolidated and swept into these destination addresses.
    |
    | You can safely change these receiving addresses anytime without disrupting
    | your Hot Gas Station fee dispenser below.
    |
    */
    'master_wallets' => [
        'ethereum'  => env('BLOCKCHAIN_MASTER_ETHEREUM'),
        'bsc'       => env('BLOCKCHAIN_MASTER_BSC'),
        'polygon'   => env('BLOCKCHAIN_MASTER_POLYGON'),
        'arbitrum'  => env('BLOCKCHAIN_MASTER_ARBITRUM'),
        'optimism'  => env('BLOCKCHAIN_MASTER_OPTIMISM'),
        'base'      => env('BLOCKCHAIN_MASTER_BASE'),
        'avalanche' => env('BLOCKCHAIN_MASTER_AVALANCHE'),
        'fantom'    => env('BLOCKCHAIN_MASTER_FANTOM'),
        'cronos'    => env('BLOCKCHAIN_MASTER_CRONOS'),
        'linea'     => env('BLOCKCHAIN_MASTER_LINEA'),
        'scroll'    => env('BLOCKCHAIN_MASTER_SCROLL'),
        'zksync'    => env('BLOCKCHAIN_MASTER_ZKSYNC'),
        'celo'      => env('BLOCKCHAIN_MASTER_CELO'),
        'mantle'    => env('BLOCKCHAIN_MASTER_MANTLE'),
        'solana'    => env('BLOCKCHAIN_MASTER_SOLANA'),
        'tron'      => env('BLOCKCHAIN_MASTER_TRON'),
        'bitcoin'   => env('BLOCKCHAIN_MASTER_BITCOIN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hot Gas Station Wallets & Private Keys (Fee Sponsorship)
    |--------------------------------------------------------------------------
    |
    | Public addresses and private keys for hot master gas stations. Used solely
    | to automatically fuel and sponsor native gas fees into dry customer deposit
    | sub-wallets before executing token sweeps.
    |
    | Private keys are automatically decrypted on-the-fly if encrypted with Laravel's APP_KEY.
    |
    */
    'master_gas_wallets' => [
        'ethereum'  => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_ETHEREUM'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_ETHEREUM'),
        ],
        'bsc'       => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_BSC'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_BSC'),
        ],
        'polygon'   => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_POLYGON'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_POLYGON'),
        ],
        'arbitrum'  => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_ARBITRUM'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_ARBITRUM'),
        ],
        'optimism'  => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_OPTIMISM'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_OPTIMISM'),
        ],
        'base'      => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_BASE'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_BASE'),
        ],
        'avalanche' => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_AVALANCHE'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_AVALANCHE'),
        ],
        'fantom'    => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_FANTOM'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_FANTOM'),
        ],
        'cronos'    => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_CRONOS'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_CRONOS'),
        ],
        'linea'     => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_LINEA'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_LINEA'),
        ],
        'scroll'    => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_SCROLL'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_SCROLL'),
        ],
        'zksync'    => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_ZKSYNC'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_ZKSYNC'),
        ],
        'celo'      => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_CELO'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_CELO'),
        ],
        'mantle'    => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_MANTLE'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_MANTLE'),
        ],
        'solana'    => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_SOLANA'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_SOLANA'),
        ],
        'tron'      => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_TRON'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_TRON'),
        ],
        'bitcoin'   => [
            'address'     => env('BLOCKCHAIN_GAS_ADDRESS_BITCOIN'),
            'private_key' => env('BLOCKCHAIN_GAS_KEY_BITCOIN'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Eloquent Models & Database Mapping
    |--------------------------------------------------------------------------
    |
    | Default BlockchainSdk models used to store custodial sub-wallets,
    | track incoming deposits, and record master vault sweeps.
    |
    */
    'models' => [
        'wallet'  => \BlockchainSdk\Laravel\Models\BlockchainSdkWallet::class,
        'deposit' => \BlockchainSdk\Laravel\Models\BlockchainSdkDeposit::class,
        'sweep'   => \BlockchainSdk\Laravel\Models\BlockchainSdkSweep::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Chain Network Configurations & Supported Tokens
    |--------------------------------------------------------------------------
    */
    'networks' => [

        // ======================== EVM NETWORKS ========================

        'ethereum' => [
            'type'       => 'evm',
            'chain_id'   => 1,
            'currency'   => 'ETH',
            'rpc_nodes'  => [
                'https://cloudflare-eth.com',
                'https://ethereum-rpc.publicnode.com',
                'https://1rpc.io/eth',
            ],
            'tokens' => [
                'USDT' => ['name' => 'Tether USD', 'contract' => '0xdAC17F958D2ee523a2206206994597C13D831ec7', 'decimals' => 6, 'status' => 'enabled'],
                'USDC' => ['name' => 'USD Coin',   'contract' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', 'decimals' => 6, 'status' => 'enabled'],
                'WBTC' => ['name' => 'Wrapped BTC','contract' => '0x2260FAC5E5542a773Aa44fBCfeDf7C193bc2C599', 'decimals' => 8, 'status' => 'enabled'],
                'DAI'  => ['name' => 'Dai Stable', 'contract' => '0x6B175474E89094C44Da98b954EedeAC495271d0F', 'decimals' => 18, 'status' => 'enabled'],
                'LINK' => ['name' => 'Chainlink',  'contract' => '0x514910771AF9Ca656af840dff83E8264EcF986CA', 'decimals' => 18, 'status' => 'enabled'],
                'UNI'  => ['name' => 'Uniswap',    'contract' => '0x1f9840a85d5aF5bf1D1762F925BDADdC4201F984', 'decimals' => 18, 'status' => 'enabled'],
            ],
        ],

        'bsc' => [
            'type'       => 'evm',
            'chain_id'   => 56,
            'currency'   => 'BNB',
            'rpc_nodes'  => [
                'https://bsc-dataseed.binance.org',
                'https://bsc-dataseed1.defibit.io',
                'https://bsc-rpc.publicnode.com',
            ],
            'tokens' => [
                'USDT'  => ['name' => 'Tether USD', 'contract' => '0x55d398326f99059fF775485246999027B3197955', 'decimals' => 18, 'status' => 'enabled'],
                'USDC'  => ['name' => 'USD Coin',   'contract' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', 'decimals' => 18, 'status' => 'enabled'],
                'BTCB'  => ['name' => 'Bitcoin BEP2', 'contract' => '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c', 'decimals' => 18, 'status' => 'enabled'],
                'ETH'   => ['name' => 'Ethereum BEP2', 'contract' => '0x2170Ed0880ac9A755fd29B2688956BD959F933F8', 'decimals' => 18, 'status' => 'enabled'],
                'CAKE'  => ['name' => 'PancakeSwap','contract' => '0x0E09FaBB73Bd3Ade0a17ECC321fD13a19e81cE82', 'decimals' => 18, 'status' => 'enabled'],
                'FDUSD' => ['name' => 'First Digital USD', 'contract' => '0xc5f0f7b66764F6ec8C8Dff7BA683102295E16409', 'decimals' => 18, 'status' => 'enabled'],
            ],
        ],

        'polygon' => [
            'type'       => 'evm',
            'chain_id'   => 137,
            'currency'   => 'POL',
            'rpc_nodes'  => [
                'https://polygon-rpc.com',
                'https://polygon-bor-rpc.publicnode.com',
                'https://1rpc.io/matic',
            ],
            'tokens' => [
                'USDT' => ['name' => 'Tether USD', 'contract' => '0xc2132D05D31c914a87C6611C10748AEb04B58e8F', 'decimals' => 6, 'status' => 'enabled'],
                'USDC' => ['name' => 'USD Coin',   'contract' => '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359', 'decimals' => 6, 'status' => 'enabled'],
                'WETH' => ['name' => 'Wrapped ETH', 'contract' => '0x7ceB23fD6bC0adD59E62ac25578270cFf1b9f619', 'decimals' => 18, 'status' => 'enabled'],
            ],
        ],

        'arbitrum' => [
            'type'       => 'evm',
            'chain_id'   => 42161,
            'currency'   => 'ETH',
            'rpc_nodes'  => [
                'https://arb1.arbitrum.io/rpc',
                'https://rpc.ankr.com/arbitrum',
            ],
            'tokens' => [
                'USDT' => ['name' => 'Tether USD', 'contract' => '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9', 'decimals' => 6, 'status' => 'enabled'],
                'USDC' => ['name' => 'USD Coin',   'contract' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831', 'decimals' => 6, 'status' => 'enabled'],
                'ARB'  => ['name' => 'Arbitrum',   'contract' => '0x912CE59144191C1204E64559FE8253a0e49E6548', 'decimals' => 18, 'status' => 'enabled'],
            ],
        ],

        'optimism' => [
            'type'       => 'evm',
            'chain_id'   => 10,
            'currency'   => 'ETH',
            'rpc_nodes'  => [
                'https://mainnet.optimism.io',
                'https://rpc.ankr.com/optimism',
            ],
            'tokens' => [
                'USDT' => ['name' => 'Tether USD', 'contract' => '0x94b008aA00579c1307B0EF2c499aD98a8ce58e58', 'decimals' => 6, 'status' => 'enabled'],
                'USDC' => ['name' => 'USD Coin',   'contract' => '0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85', 'decimals' => 6, 'status' => 'enabled'],
                'OP'   => ['name' => 'Optimism',   'contract' => '0x4200000000000000000000000000000000000042', 'decimals' => 18, 'status' => 'enabled'],
            ],
        ],

        'base' => [
            'type'       => 'evm',
            'chain_id'   => 8453,
            'currency'   => 'ETH',
            'rpc_nodes'  => [
                'https://mainnet.base.org',
                'https://base-rpc.publicnode.com',
                'https://1rpc.io/base',
            ],
            'tokens' => [
                'USDC'  => ['name' => 'USD Coin',   'contract' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', 'decimals' => 6, 'status' => 'enabled'],
                'USDT'  => ['name' => 'Tether USD', 'contract' => '0xfde4C96c8593536E31F229EA8f37b2ADa2699bb2', 'decimals' => 6, 'status' => 'enabled'],
                'cbBTC' => ['name' => 'Coinbase Wrapped BTC', 'contract' => '0xcbB7C0000aB88B473b1f5aFd9ef808440eed33Bf', 'decimals' => 8, 'status' => 'enabled'],
                'AERO'  => ['name' => 'Aerodrome',  'contract' => '0x940181a94A35A4569E4529A3CDfB74e38FD98631', 'decimals' => 18, 'status' => 'enabled'],
            ],
        ],

        'avalanche' => [
            'type'       => 'evm',
            'chain_id'   => 43114,
            'currency'   => 'AVAX',
            'rpc_nodes'  => [
                'https://api.avax.network/ext/bc/C/rpc',
                'https://avalanche-c-chain-rpc.publicnode.com',
            ],
            'tokens' => [
                'USDT' => ['name' => 'Tether USD', 'contract' => '0x9702230A8Ea53601f5cD2dc00fDBc13d4dF4A8c7', 'decimals' => 6, 'status' => 'enabled'],
                'USDC' => ['name' => 'USD Coin',   'contract' => '0xB97EF9Ef8734C71904D8002F8b6Bc66Dd9c48a6E', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'fantom' => [
            'type'       => 'evm',
            'chain_id'   => 250,
            'currency'   => 'FTM',
            'rpc_nodes'  => ['https://rpc.ftm.tools', 'https://fantom.drpc.org', 'https://rpcapi.fantom.network'],
            'tokens'     => [
                'USDC' => ['name' => 'USD Coin', 'contract' => '0x04068DA6C83AFCFA0e13ba15A6696662335D5B75', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'cronos' => [
            'type'       => 'evm',
            'chain_id'   => 25,
            'currency'   => 'CRO',
            'rpc_nodes'  => ['https://evm.cronos.org', 'https://cronos-evm-rpc.publicnode.com'],
            'tokens'     => [
                'USDC' => ['name' => 'USD Coin', 'contract' => '0xc21223249CA28397B4B6541dfFaEcC539BfF0c59', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'linea' => [
            'type'       => 'evm',
            'chain_id'   => 59144,
            'currency'   => 'ETH',
            'rpc_nodes'  => ['https://rpc.linea.build', 'https://linea-rpc.publicnode.com', 'https://1rpc.io/linea'],
            'tokens'     => [
                'USDC' => ['name' => 'USD Coin', 'contract' => '0x176211869cA2b568f2A7D4EE941E073a821EE1ff', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'scroll' => [
            'type'       => 'evm',
            'chain_id'   => 534352,
            'currency'   => 'ETH',
            'rpc_nodes'  => ['https://rpc.scroll.io', 'https://scroll-rpc.publicnode.com', 'https://1rpc.io/scroll'],
            'tokens'     => [
                'USDC' => ['name' => 'USD Coin', 'contract' => '0x06eFdBF5e0942eF74522dF9b1384e2aC0422551A', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'zksync' => [
            'type'       => 'evm',
            'chain_id'   => 324,
            'currency'   => 'ETH',
            'rpc_nodes'  => ['https://mainnet.era.zksync.io', 'https://zksync-era-rpc.publicnode.com', 'https://1rpc.io/zksync2-era'],
            'tokens'     => [
                'USDC' => ['name' => 'USD Coin', 'contract' => '0x1d17CBcF0D6D143135aE902365D2E5e2A16538D4', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'celo' => [
            'type'       => 'evm',
            'chain_id'   => 42220,
            'currency'   => 'CELO',
            'rpc_nodes'  => ['https://forno.celo.org', 'https://celo-rpc.publicnode.com', 'https://1rpc.io/celo'],
            'tokens'     => [
                'USDT' => ['name' => 'Tether USD', 'contract' => '0x48065fbBE25f71C9282ddf5e1cD6D6A887483D5e', 'decimals' => 6, 'status' => 'enabled'],
                'cUSD' => ['name' => 'Celo Dollar','contract' => '0x765DE816845861e75A25fCA122bb6898B8B1282a', 'decimals' => 18, 'status' => 'enabled'],
            ],
        ],

        'mantle' => [
            'type'       => 'evm',
            'chain_id'   => 5000,
            'currency'   => 'MNT',
            'rpc_nodes'  => ['https://rpc.mantle.xyz', 'https://mantle-rpc.publicnode.com', 'https://1rpc.io/mantle'],
            'tokens'     => [
                'USDT' => ['name' => 'Tether USD', 'contract' => '0x201EBa5CC46D216Ce6DC03F6a759e8E766e956aE', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        // ======================== NON-EVM NETWORKS ========================

        'solana' => [
            'type'       => 'solana',
            'currency'   => 'SOL',
            'rpc_nodes'  => [
                'https://api.mainnet-beta.solana.com',
                'https://solana-rpc.publicnode.com',
            ],
            'tokens' => [
                'USDC' => ['name' => 'USD Coin', 'contract' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v', 'decimals' => 6, 'status' => 'enabled'],
                'USDT' => ['name' => 'Tether USD','contract' => 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB', 'decimals' => 6, 'status' => 'enabled'],
                'PYTH' => ['name' => 'Pyth Network', 'contract' => 'HZ1JovNiDcBaKhQPPBpdu5jWALVyNYxgYrmETAb335L', 'decimals' => 6, 'status' => 'enabled'],
                'JUP'  => ['name' => 'Jupiter',  'contract' => 'JUPyiwrYJFskUPiHa7hkeR8VUtAeFoSYbKedZNsDvCN', 'decimals' => 6, 'status' => 'enabled'],
                'BONK' => ['name' => 'Bonk',     'contract' => 'DezXAZ8z7PnrnRJjz3wXBoRgixCa6xjnB7YaB1pPB263', 'decimals' => 5, 'status' => 'enabled'],
            ],
        ],

        'tron' => [
            'type'       => 'tron',
            'currency'   => 'TRX',
            'rpc_nodes'  => [
                'https://api.trongrid.io',
                'https://api.tronstack.io',
            ],
            'api_key'    => env('TRON_PRO_API_KEY'),
            'tokens' => [
                'USDT' => ['name' => 'Tether USD', 'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 'decimals' => 6, 'status' => 'enabled'],
                'USDC' => ['name' => 'USD Coin',   'contract' => 'TEkxiTehnzSmSe2XqrBj4w32RUN966rdz8', 'decimals' => 6, 'status' => 'enabled'],
                'USDD' => ['name' => 'Decentralized USD', 'contract' => 'TPYmHEhy5n8TCEfYGqW2rPxsghSfzghPDn', 'decimals' => 18, 'status' => 'enabled'],
            ],
        ],

        'bitcoin' => [
            'type'       => 'bitcoin',
            'currency'   => 'BTC',
            'rpc_nodes'  => [
                'https://blockstream.info/api',
                'https://mempool.space/api',
            ],
            'tokens'     => [],
        ],
    ],
];