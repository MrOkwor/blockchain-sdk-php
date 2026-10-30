<?php

namespace BlockchainSdk\Tests;

use BlockchainSdk\Crypto\Base58;
use BlockchainSdk\Drivers\Bitcoin\BitcoinDriver;
use BlockchainSdk\Drivers\Solana\SolanaDriver;
use BlockchainSdk\Drivers\Tron\TronDriver;
use BlockchainSdk\Http\RpcClient;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class IncomingTransactionsTest extends TestCase
{
    private function injectRpc(object $driver, RpcClient $rpc): void
    {
        $reflector = new ReflectionProperty($driver, 'rpc');
        $reflector->setValue($driver, $rpc);
    }

    public function test_tron_driver_detects_incoming_trc20_token_transfers(): void
    {
        $rpc = $this->createMock(RpcClient::class);

        $recipientHex = '418840e6c55b9ada3265940315e6b7201a1d07f772';
        $recipientBase58 = Base58::encodeCheck(hex2bin($recipientHex));
        $senderHex = '414321432143214321432143214321432143214321';
        $contractHex = '41a614f803b6fd780986a42c78ec9c7f77e6ded13c';
        $contractBase58 = Base58::encodeCheck(hex2bin($contractHex));

        $rpc->expects($this->any())
            ->method('post')
            ->willReturnCallback(function (string $path) use ($recipientHex, $senderHex, $contractHex) {
                if ($path === 'wallet/getnowblock') {
                    return [
                        'block_header' => [
                            'raw_data' => [
                                'number' => 1000,
                            ],
                        ],
                    ];
                }

                if ($path === 'wallet/gettransactioninfobyid') {
                    $logSender = str_pad(substr($senderHex, 2), 64, '0', STR_PAD_LEFT);
                    $logRecipient = str_pad(substr($recipientHex, 2), 64, '0', STR_PAD_LEFT);

                    return [
                        'id' => '0x_tron_token_tx_1',
                        'blockNumber' => 990,
                        'receipt' => ['result' => 'SUCCESS'],
                        'log' => [
                            [
                                'address' => $contractHex,
                                'topics' => [
                                    'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                                    $logSender,
                                    $logRecipient,
                                ],
                                'data' => '0000000000000000000000000000000000000000000000000000000005f5e100', // 100,000,000 (100 USDT)
                            ],
                        ],
                    ];
                }

                return [];
            });

        $rpc->expects($this->any())
            ->method('get')
            ->willReturnCallback(function (string $path) {
                if (str_contains($path, 'transactions/trc20')) {
                    return [
                        'success' => true,
                        'data' => [
                            ['transaction_id' => '0x_tron_token_tx_1'],
                        ],
                    ];
                }

                return [];
            });

        $driver = new TronDriver([]);
        $this->injectRpc($driver, $rpc);
        $transfers = $driver->getIncomingTransactions($recipientBase58, $contractBase58, 6, 5);

        $this->assertCount(1, $transfers);
        $this->assertSame('0x_tron_token_tx_1', $transfers[0]['tx_hash']);
        $this->assertSame('100.000000', (string) $transfers[0]['amount']);
        $this->assertSame(11, $transfers[0]['confirmations']);
        $this->assertSame(990, $transfers[0]['block_number']);
        $this->assertSame($recipientBase58, $transfers[0]['to_address']);
    }

    public function test_tron_driver_detects_native_trx_transfers(): void
    {
        $rpc = $this->createMock(RpcClient::class);

        $recipientHex = '418840e6c55b9ada3265940315e6b7201a1d07f772';
        $recipientBase58 = Base58::encodeCheck(hex2bin($recipientHex));
        $senderHex = '414321432143214321432143214321432143214321';

        $rpc->expects($this->any())
            ->method('post')
            ->willReturnCallback(function (string $path) {
                if ($path === 'wallet/getnowblock') {
                    return [
                        'block_header' => [
                            'raw_data' => [
                                'number' => 2000,
                            ],
                        ],
                    ];
                }

                if ($path === 'wallet/gettransactioninfobyid') {
                    return [
                        'id' => '0x_tron_native_tx_1',
                        'blockNumber' => 1995,
                        'receipt' => ['result' => 'SUCCESS'],
                    ];
                }

                return [];
            });

        $rpc->expects($this->any())
            ->method('get')
            ->willReturnCallback(function (string $path) use ($recipientHex, $senderHex) {
                if (str_contains($path, '/transactions')) {
                    return [
                        'success' => true,
                        'data' => [
                            [
                                'txID' => '0x_tron_native_tx_1',
                                'raw_data' => [
                                    'contract' => [
                                        [
                                            'type' => 'TransferContract',
                                            'parameter' => [
                                                'value' => [
                                                    'amount' => 50000000, // 50 TRX
                                                    'owner_address' => $senderHex,
                                                    'to_address' => $recipientHex,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                return [];
            });

        $driver = new TronDriver([]);
        $this->injectRpc($driver, $rpc);
        $transfers = $driver->getIncomingTransactions($recipientBase58, null, 6, 5);

        $this->assertCount(1, $transfers);
        $this->assertSame('0x_tron_native_tx_1', $transfers[0]['tx_hash']);
        $this->assertSame('50.000000', (string) $transfers[0]['amount']);
        $this->assertSame(6, $transfers[0]['confirmations']);
        $this->assertSame(1995, $transfers[0]['block_number']);
    }

    public function test_solana_driver_detects_spl_token_transfers(): void
    {
        $rpc = $this->createMock(RpcClient::class);

        $tokenAccount = 'SolTokenAccount11111111111111111111111111111';
        $ownerAddress = 'SolOwnerAddress11111111111111111111111111111';
        $mintContract = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';

        $rpc->expects($this->any())
            ->method('call')
            ->willReturnCallback(function (string $method) use ($tokenAccount, $ownerAddress, $mintContract) {
                if ($method === 'getTokenAccountsByOwner') {
                    return [
                        'result' => [
                            'value' => [
                                [
                                    'pubkey' => $tokenAccount,
                                    'account' => [
                                        'data' => [
                                            'parsed' => [
                                                'info' => [
                                                    'mint' => $mintContract,
                                                    'owner' => $ownerAddress,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                if ($method === 'getSignaturesForAddress') {
                    return [
                        'result' => [
                            ['signature' => 'sol_spl_sig_1', 'err' => null],
                        ],
                    ];
                }

                if ($method === 'getSlot') {
                    return [
                        'result' => 250,
                    ];
                }

                if ($method === 'getTransaction') {
                    return [
                        'result' => [
                            'slot' => 240,
                            'meta' => ['err' => null, 'innerInstructions' => []],
                            'transaction' => [
                                'message' => [
                                    'instructions' => [
                                        [
                                            'program' => 'spl-token',
                                            'parsed' => [
                                                'type' => 'transferChecked',
                                                'info' => [
                                                    'destination' => $tokenAccount,
                                                    'source' => 'sender_token_account',
                                                    'mint' => $mintContract,
                                                    'tokenAmount' => [
                                                        'amount' => '15000000',
                                                        'decimals' => 6,
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                return [];
            });

        $driver = new SolanaDriver([]);
        $this->injectRpc($driver, $rpc);
        $transfers = $driver->getIncomingTransactions($ownerAddress, $mintContract, 6, 5);

        $this->assertCount(1, $transfers);
        $this->assertSame('sol_spl_sig_1', $transfers[0]['tx_hash']);
        $this->assertSame('15.000000', (string) $transfers[0]['amount']);
        $this->assertSame(11, $transfers[0]['confirmations']);
        $this->assertSame(240, $transfers[0]['block_number']);
    }

    public function test_solana_driver_detects_native_sol_transfers(): void
    {
        $rpc = $this->createMock(RpcClient::class);
        $ownerAddress = 'SolOwnerAddress11111111111111111111111111111';

        $rpc->expects($this->any())
            ->method('call')
            ->willReturnCallback(function (string $method) use ($ownerAddress) {
                if ($method === 'getSignaturesForAddress') {
                    return [
                        'result' => [
                            ['signature' => 'sol_native_sig_1', 'err' => null],
                        ],
                    ];
                }

                if ($method === 'getSlot') {
                    return [
                        'result' => 300,
                    ];
                }

                if ($method === 'getTransaction') {
                    return [
                        'result' => [
                            'slot' => 295,
                            'meta' => [
                                'err' => null,
                                'innerInstructions' => [
                                    [
                                        'index' => 0,
                                        'instructions' => [
                                            [
                                                'program' => 'system',
                                                'parsed' => [
                                                    'type' => 'transfer',
                                                    'info' => [
                                                        'destination' => $ownerAddress,
                                                        'source' => 'sol_sender_account',
                                                        'lamports' => 2500000000, // 2.5 SOL
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'transaction' => [
                                'message' => [
                                    'instructions' => [],
                                ],
                            ],
                        ],
                    ];
                }

                return [];
            });

        $driver = new SolanaDriver([]);
        $this->injectRpc($driver, $rpc);
        $transfers = $driver->getIncomingTransactions($ownerAddress, null, 9, 5);

        $this->assertCount(1, $transfers);
        $this->assertSame('sol_native_sig_1', $transfers[0]['tx_hash']);
        $this->assertSame(0, $transfers[0]['log_index']);
        $this->assertSame('2.500000000', (string) $transfers[0]['amount']);
        $this->assertSame(6, $transfers[0]['confirmations']);
    }

    public function test_bitcoin_driver_detects_incoming_transfers_and_excludes_change(): void
    {
        $rpc = $this->createMock(RpcClient::class);
        $recipient = 'bc1q_btc_deposit_address';

        $rpc->expects($this->any())
            ->method('get')
            ->willReturnCallback(function (string $path) use ($recipient) {
                if ($path === 'blocks') {
                    return [
                        [
                            'height' => 850000,
                        ],
                    ];
                }

                if ($path === "address/{$recipient}/txs") {
                    return [
                        [
                            'txid' => 'btc_valid_incoming_1',
                            'status' => [
                                'confirmed' => true,
                                'block_height' => 849995,
                            ],
                            'vin' => [
                                [
                                    'prevout' => [
                                        'scriptpubkey_address' => 'bc1q_external_sender',
                                        'value' => 100000000,
                                    ],
                                ],
                            ],
                            'vout' => [
                                [
                                    'scriptpubkey_address' => 'bc1q_change_back_to_sender',
                                    'value' => 70000000,
                                ],
                                [
                                    'scriptpubkey_address' => $recipient,
                                    'value' => 30000000, // 0.3 BTC
                                ],
                            ],
                        ],
                        [
                            'txid' => 'btc_outgoing_change_ignored',
                            'status' => [
                                'confirmed' => true,
                                'block_height' => 849990,
                            ],
                            'vin' => [
                                [
                                    'prevout' => [
                                        'scriptpubkey_address' => $recipient,
                                        'value' => 50000000,
                                    ],
                                ],
                            ],
                            'vout' => [
                                [
                                    'scriptpubkey_address' => $recipient,
                                    'value' => 20000000,
                                ],
                            ],
                        ],
                    ];
                }

                return [];
            });

        $driver = new BitcoinDriver([]);
        $this->injectRpc($driver, $rpc);
        $transfers = $driver->getIncomingTransactions($recipient, null, 8, 5);

        $this->assertCount(1, $transfers);
        $this->assertSame('btc_valid_incoming_1', $transfers[0]['tx_hash']);
        $this->assertSame(1, $transfers[0]['log_index']);
        $this->assertSame('0.30000000', (string) $transfers[0]['amount']);
        $this->assertSame(6, $transfers[0]['confirmations']);
    }
}
