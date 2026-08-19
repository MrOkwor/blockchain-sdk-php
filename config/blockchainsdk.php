<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Network Driver
    |--------------------------------------------------------------------------
    |
    | Default blockchain network driver to use when none is specified.
    |
    */
    'default' => env('BLOCKCHAIN_DEFAULT_NETWORK', 'ethereum'),

    /*
    |--------------------------------------------------------------------------
    | Central Master / Cold Vault Addresses
    |--------------------------------------------------------------------------
    |
    | Destination wallet addresses used by automated sweepers to consolidate
    | customer deposits from individual deposit addresses.
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
    | Hot Gas Station / Fee Sponsor Private Keys
    |--------------------------------------------------------------------------
    |
    | Private keys for hot master wallets used to automatically fuel / sponsor
    | native gas fees into customer deposit sub-wallets before sweeping tokens.
    |
    */
    'master_gas_keys' => [
        'ethereum'  => env('BLOCKCHAIN_GAS_KEY_ETHEREUM'),
        'bsc'       => env('BLOCKCHAIN_GAS_KEY_BSC'),
        'polygon'   => env('BLOCKCHAIN_GAS_KEY_POLYGON'),
        'arbitrum'  => env('BLOCKCHAIN_GAS_KEY_ARBITRUM'),
        'optimism'  => env('BLOCKCHAIN_GAS_KEY_OPTIMISM'),
        'base'      => env('BLOCKCHAIN_GAS_KEY_BASE'),
        'avalanche' => env('BLOCKCHAIN_GAS_KEY_AVALANCHE'),
        'fantom'    => env('BLOCKCHAIN_GAS_KEY_FANTOM'),
        'cronos'    => env('BLOCKCHAIN_GAS_KEY_CRONOS'),
        'linea'     => env('BLOCKCHAIN_GAS_KEY_LINEA'),
        'scroll'    => env('BLOCKCHAIN_GAS_KEY_SCROLL'),
        'zksync'    => env('BLOCKCHAIN_GAS_KEY_ZKSYNC'),
        'celo'      => env('BLOCKCHAIN_GAS_KEY_CELO'),
        'mantle'    => env('BLOCKCHAIN_GAS_KEY_MANTLE'),
        'solana'    => env('BLOCKCHAIN_GAS_KEY_SOLANA'),
        'tron'      => env('BLOCKCHAIN_GAS_KEY_TRON'),
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
    'networks' => [

        // ======================== EVM NETWORKS ========================

        'ethereum' => [
            'type'       => 'evm',
            'chain_id'   => 1,
            'name'       => 'Ethereum',
            'currency'   => 'ETH',
            'rpc_nodes'  => [
                env('ETH_RPC_URL', 'https://eth-mainnet.g.alchemy.com/public'),
                'https://cloudflare-eth.com',
                'https://rpc.ankr.com/eth',
            ],
            'tokens' => [
                'USDT' => ['name' => 'Tether USD', 'contract' => '0xdAC17F958D2ee523a2206206994597C13D831ec7', 'decimals' => 6, 'status' => 'enabled'],
                'USDC' => ['name' => 'USD Coin', 'contract' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', 'decimals' => 6, 'status' => 'enabled'],
                'WBTC' => ['name' => 'Wrapped BTC', 'contract' => '0x2260FAC5E5542a773Aa44fBCfeDf7C193bc2C599', 'decimals' => 8, 'status' => 'enabled'],
                'LINK' => ['name' => 'Chainlink', 'contract' => '0x514910771AF9Ca656af840dff83E8264EcF986CA', 'decimals' => 18, 'status' => 'enabled'],
                'UNI'  => ['name' => 'Uniswap', 'contract' => '0x1f9840a85d5aF5bf1D1762F925BDADdC4201F984', 'decimals' => 18, 'status' => 'enabled'],
                'BUSD' => ['name' => 'Binance USD', 'contract' => '0x4Fabb145d64652a948d72533023f6E7A623C7C53', 'decimals' => 18, 'status' => 'enabled'],
            ],
        ],

        'bsc' => [
            'type'       => 'evm',
            'chain_id'   => 56,
            'name'       => 'BNB Smart Chain',
            'currency'   => 'BNB',
            'rpc_nodes'  => [
                env('BSC_RPC_URL', 'https://bsc-rpc.publicnode.com'),
                'https://bsc-dataseed.binance.org',
                'https://rpc.ankr.com/bsc',
            ],
            'tokens' => [
                'USDT'  => ['name' => 'Tether USD', 'contract' => '0x55d398326f99059fF775485246999027B3197955', 'decimals' => 18, 'status' => 'enabled'],
                'USDC'  => ['name' => 'USD Coin', 'contract' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', 'decimals' => 18, 'status' => 'enabled'],
                'WBTC'  => ['name' => 'Wrapped BTC (BNB Chain)', 'contract' => '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c', 'decimals' => 18, 'status' => 'enabled'],
                'LINK'  => ['name' => 'Chainlink', 'contract' => '0xF8A0BF9cF54Bb92F17374d9e9A321E6a111a51bD', 'decimals' => 18, 'status' => 'enabled'],
                'CAKE'  => ['name' => 'PancakeSwap', 'contract' => '0x0E09FaBB73Bd3Ade0a17ECC321fD13a19e81cE82', 'decimals' => 18, 'status' => 'enabled'],
                'FDUSD' => ['name' => 'First Digital USD', 'contract' => '0xc5f0f7b66764F6ec8C8Dff7BA683102295E16409', 'decimals' => 18, 'status' => 'enabled'],
                'WBNB'  => ['name' => 'Wrapped BNB', 'contract' => '0xbb4CdB9CBd36B01bD1cBaEBF2De08d9173bc095c', 'decimals' => 18, 'status' => 'enabled'],
                'BUSD'  => ['name' => 'Binance USD', 'contract' => '0xe9e7CEA3DedcA5984780Bafc599bD69ADd087D56', 'decimals' => 18, 'status' => 'enabled'],
                'XRP'   => ['name' => 'Binance-Peg XRP', 'contract' => '0x1D2F0da169ceB9fC7B3144628dB156f3F6c60dBE', 'decimals' => 18, 'status' => 'enabled'],
            ],
        ],

        'polygon' => [
            'type'       => 'evm',
            'chain_id'   => 137,
            'name'       => 'Polygon',
            'currency'   => 'MATIC',
            'rpc_nodes'  => [
                env('POLYGON_RPC_URL', 'https://polygon-bor-rpc.publicnode.com'),
                'https://polygon-rpc.com',
                'https://rpc.ankr.com/polygon',
            ],
            'tokens' => [
                'USDT'   => ['name' => 'Tether USD', 'contract' => '0xc2132D05D31c914a87C6611C10748AEb04B58e8F', 'decimals' => 6, 'status' => 'enabled'],
                'USDC'   => ['name' => 'USD Coin (Native)', 'contract' => '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359', 'decimals' => 6, 'status' => 'enabled'],
                'USDC.e' => ['name' => 'USD Coin (Bridged)', 'contract' => '0x2791Bca1f2de4661ED88A30C99A7a9449Aa84174', 'decimals' => 6, 'status' => 'enabled'],
                'POL'    => ['name' => 'POL', 'contract' => '0x455e53CBB86018Ac2B8092FdCd39d8444aFFC3f6', 'decimals' => 18, 'status' => 'enabled'],
            ],
        ],

        'arbitrum' => [
            'type'       => 'evm',
            'chain_id'   => 42161,
            'name'       => 'Arbitrum One',
            'currency'   => 'ETH',
            'rpc_nodes'  => [
                env('ARBITRUM_RPC_URL', 'https://arb1.arbitrum.io/rpc'),
                'https://rpc.ankr.com/arbitrum',
            ],
            'tokens' => [
                'USDT'   => ['name' => 'Tether USD', 'contract' => '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9', 'decimals' => 6, 'status' => 'enabled'],
                'USDC'   => ['name' => 'USD Coin (Native)', 'contract' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831', 'decimals' => 6, 'status' => 'enabled'],
                'USDC.e' => ['name' => 'USD Coin (Bridged)', 'contract' => '0xFF970A61A04b1cA14834A43f5dE4533eBDDB5CC8', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'optimism' => [
            'type'       => 'evm',
            'chain_id'   => 10,
            'name'       => 'Optimism',
            'currency'   => 'ETH',
            'rpc_nodes'  => [
                env('OPTIMISM_RPC_URL', 'https://mainnet.optimism.io'),
                'https://rpc.ankr.com/optimism',
            ],
            'tokens' => [
                'USDT'   => ['name' => 'Tether USD', 'contract' => '0x94b008aA00579c1307B0EF2c499aD98a8ce58e58', 'decimals' => 6, 'status' => 'enabled'],
                'USDC'   => ['name' => 'USD Coin (Native)', 'contract' => '0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85', 'decimals' => 6, 'status' => 'enabled'],
                'USDC.e' => ['name' => 'USD Coin (Bridged)', 'contract' => '0x7F5c764cBc14f9669B88837ca1490cCa17c31607', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'base' => [
            'type'       => 'evm',
            'chain_id'   => 8453,
            'name'       => 'Base',
            'currency'   => 'ETH',
            'rpc_nodes'  => [
                env('BASE_RPC_URL', 'https://mainnet.base.org'),
                'https://base-rpc.publicnode.com',
            ],
            'tokens' => [
                'USDC'  => ['name' => 'USD Coin', 'contract' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', 'decimals' => 6, 'status' => 'enabled'],
                'USDT'  => ['name' => 'Tether USD', 'contract' => '0xfde4C96c8593536E31F229EA8f37b2ADa2699bb2', 'decimals' => 6, 'status' => 'enabled'],
                'WETH'  => ['name' => 'Wrapped Ether', 'contract' => '0x4200000000000000000000000000000000000006', 'decimals' => 18, 'status' => 'enabled'],
                'DAI'   => ['name' => 'Dai Stablecoin', 'contract' => '0x50c5725949A6F0c72E6C4a641F24049A917DB0Cb', 'decimals' => 18, 'status' => 'enabled'],
                'LINK'  => ['name' => 'Chainlink', 'contract' => '0x88Fb150BDc53A65fe94Dea0c9BA0a6dAf8C6e196', 'decimals' => 18, 'status' => 'enabled'],
                'cbBTC' => ['name' => 'Coinbase Wrapped BTC', 'contract' => '0xcbB7C0000aB88B473b1f5aFd9ef808440eed33Bf', 'decimals' => 8, 'status' => 'enabled'],
                'AERO'  => ['name' => 'Aerodrome', 'contract' => '0x940181a94A35A4569E4529A3CDfB74e38FD98631', 'decimals' => 18, 'status' => 'enabled'],
                'BRETT' => ['name' => 'Brett', 'contract' => '0x532f27101965dd16442E59d40670FaF5eBB142E4', 'decimals' => 18, 'status' => 'enabled'],
            ],
        ],

        'avalanche' => [
            'type'       => 'evm',
            'chain_id'   => 43114,
            'name'       => 'Avalanche C-Chain',
            'currency'   => 'AVAX',
            'rpc_nodes'  => [
                env('AVALANCHE_RPC_URL', 'https://api.avax.network/ext/bc/C/rpc'),
                'https://rpc.ankr.com/avalanche',
            ],
            'tokens' => [
                'USDT'   => ['name' => 'Tether USD', 'contract' => '0x9702230A8Ea53601f5cD2dc00fDBc13d4dF4A8c7', 'decimals' => 6, 'status' => 'enabled'],
                'USDC'   => ['name' => 'USD Coin (Native)', 'contract' => '0xB97EF9Ef8734C71904D8002F8b6Bc66Dd9c48a6E', 'decimals' => 6, 'status' => 'enabled'],
                'USDC.e' => ['name' => 'USD Coin (Bridged)', 'contract' => '0xA7D7079b0FEaD91F3e65f86E8915Cb59c1a4C664', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'fantom' => [
            'type'       => 'evm',
            'chain_id'   => 250,
            'name'       => 'Fantom Opera',
            'currency'   => 'FTM',
            'rpc_nodes'  => [
                env('FANTOM_RPC_URL', 'https://rpc.ankr.com/fantom'),
                'https://rpcapi.fantom.network',
            ],
            'tokens' => [
                'USDC' => ['name' => 'USD Coin', 'contract' => '0x04068DA6C83AFCFA0e13ba15A6696662335D5B75', 'decimals' => 6, 'status' => 'enabled'],
                'USDT' => ['name' => 'Tether USD', 'contract' => '0x049d68029688eAbF473097a2fC38ef61633A3C7A', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'cronos' => [
            'type'       => 'evm',
            'chain_id'   => 25,
            'name'       => 'Cronos Chain',
            'currency'   => 'CRO',
            'rpc_nodes'  => [
                env('CRONOS_RPC_URL', 'https://evm.cronos.org'),
                'https://cronosrpc-1.xstaking.sg',
            ],
            'tokens' => [
                'USDC' => ['name' => 'USD Coin', 'contract' => '0xc21223249CA28397B4B6541dfFaEcC539BfF0c59', 'decimals' => 6, 'status' => 'enabled'],
                'USDT' => ['name' => 'Tether USD', 'contract' => '0x66e428c3f67a68878562e79A0234c1F83c208770', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'linea' => [
            'type'       => 'evm',
            'chain_id'   => 59144,
            'name'       => 'Linea',
            'currency'   => 'ETH',
            'rpc_nodes'  => [
                env('LINEA_RPC_URL', 'https://rpc.linea.build'),
            ],
            'tokens' => [
                'USDC' => ['name' => 'USD Coin', 'contract' => '0x176211869cA2b568f2A7D4EE941E073a821EE1ff', 'decimals' => 6, 'status' => 'enabled'],
                'USDT' => ['name' => 'Tether USD', 'contract' => '0xA219439258ca9da29E9Cc4cE5596924745e12B93', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'scroll' => [
            'type'       => 'evm',
            'chain_id'   => 534352,
            'name'       => 'Scroll',
            'currency'   => 'ETH',
            'rpc_nodes'  => [
                env('SCROLL_RPC_URL', 'https://rpc.scroll.io'),
            ],
            'tokens' => [
                'USDC' => ['name' => 'USD Coin', 'contract' => '0x06eFdBF5e07333f420495273248349f5567309e1', 'decimals' => 6, 'status' => 'enabled'],
                'USDT' => ['name' => 'Tether USD', 'contract' => '0xf55BEC9cafDbE8730f096Aa55dad6D22d44099Df', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'zksync' => [
            'type'       => 'evm',
            'chain_id'   => 324,
            'name'       => 'zkSync Era',
            'currency'   => 'ETH',
            'rpc_nodes'  => [
                env('ZKSYNC_RPC_URL', 'https://mainnet.era.zksync.io'),
            ],
            'tokens' => [
                'USDC' => ['name' => 'USD Coin', 'contract' => '0x3355df6D4c9C3035724Fd0e3914dE96A5a83aaf4', 'decimals' => 6, 'status' => 'enabled'],
                'USDT' => ['name' => 'Tether USD', 'contract' => '0x493257fD37EDB34451f62EDf8D2a0C418852bA4C', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'celo' => [
            'type'       => 'evm',
            'chain_id'   => 42220,
            'name'       => 'Celo',
            'currency'   => 'CELO',
            'rpc_nodes'  => [
                env('CELO_RPC_URL', 'https://forno.celo.org'),
            ],
            'tokens' => [
                'USDC' => ['name' => 'USD Coin', 'contract' => '0xef4229c8c3250C675F21bCEfa42f58Efbf60021B', 'decimals' => 6, 'status' => 'enabled'],
                'USDT' => ['name' => 'Tether USD', 'contract' => '0x48065fbBE25f71C9282ddf5e1cD6D6A887483D5e', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        'mantle' => [
            'type'       => 'evm',
            'chain_id'   => 5000,
            'name'       => 'Mantle',
            'currency'   => 'MNT',
            'rpc_nodes'  => [
                env('MANTLE_RPC_URL', 'https://rpc.mantle.xyz'),
            ],
            'tokens' => [
                'USDC' => ['name' => 'USD Coin', 'contract' => '0x09Bc4E0D864854c6aFB6eB9A9cdF58aC190D0dF9', 'decimals' => 6, 'status' => 'enabled'],
                'USDT' => ['name' => 'Tether USD', 'contract' => '0x201EBa5CC46D216Ce6DC03F6a759e8E766e956aE', 'decimals' => 6, 'status' => 'enabled'],
            ],
        ],

        // ======================== NON-EVM NETWORKS ========================

        'solana' => [
            'type'       => 'solana',
            'name'       => 'Solana',
            'currency'   => 'SOL',
            'rpc_nodes'  => [
                env('SOLANA_RPC_URL', 'https://api.mainnet-beta.solana.com'),
                'https://solana-mainnet.rpc.extrnode.com',
            ],
            'tokens' => [
                'USDC' => ['name' => 'USD Coin', 'contract' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v', 'decimals' => 6, 'status' => 'enabled'],
                'USDT' => ['name' => 'Tether USD', 'contract' => 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB', 'decimals' => 6, 'status' => 'enabled'],
                'JUP'  => ['name' => 'Jupiter', 'contract' => 'JUPyiwrYJFskUPiHa7hkeR8VUtAeFoSYbKedZNsDvCN', 'decimals' => 6, 'status' => 'enabled'],
                'PYTH' => ['name' => 'Pyth Network', 'contract' => 'HZ1JovNiRvGrGNiiYvEozEVgZ58xaU3RKwX8eACQBCt3', 'decimals' => 6, 'status' => 'enabled'],
                'BONK' => ['name' => 'Bonk', 'contract' => 'DezXAZ8z7PnrnRJjz3wXBoRgixCa6xjnB7YaB1pPB263', 'decimals' => 5, 'status' => 'enabled'],
            ],
        ],

        'tron' => [
            'type'       => 'tron',
            'name'       => 'TRON',
            'currency'   => 'TRX',
            'rpc_nodes'  => [
                env('TRON_RPC_URL', 'https://api.trongrid.io'),
                'https://api.tronstack.io',
            ],
            'api_key'    => env('TRONGRID_API_KEY'),
            'tokens' => [
                'USDT' => ['name' => 'Tether USD', 'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 'decimals' => 6, 'status' => 'enabled'],
                'USDC' => ['name' => 'USD Coin', 'contract' => 'TEkxiTehnzSmSe2XqrBj4w32RUN966rdz8', 'decimals' => 6, 'status' => 'enabled'],
                'USDD' => ['name' => 'Decentralized USD', 'contract' => 'TPYmHEhy5n8TCEfYGqW2rPxsghSfzghPDn', 'decimals' => 18, 'status' => 'enabled'],
                'JST'  => ['name' => 'JUST', 'contract' => 'TCFLLCrqnUrYrHGm1CrnbdjvdRdjRq7QK4', 'decimals' => 18, 'status' => 'enabled'],
                'SUN'  => ['name' => 'SUN Token', 'contract' => 'TSSMHYeV2uE9qYH95DqyoCuNCzEL1NvU3S', 'decimals' => 18, 'status' => 'enabled'],
                'WIN'  => ['name' => 'WINkLink', 'contract' => 'TLa2f6VPqDgRE67v1736s7bJ8Ray5wYjU7', 'decimals' => 6, 'status' => 'enabled'],
                'BTT'  => ['name' => 'BitTorrent', 'contract' => 'TAFjULxiVgTxsUVgahxqqf2AqULvgNd6xK', 'decimals' => 18, 'status' => 'enabled'],
            ],
        ],

        'bitcoin' => [
            'type'       => 'bitcoin',
            'name'       => 'Bitcoin',
            'currency'   => 'BTC',
            'rpc_nodes'  => [
                env('BITCOIN_RPC_URL', 'https://mempool.space/api'),
                'https://blockstream.info/api',
            ],
            'tokens' => [],
        ],

    ],
];