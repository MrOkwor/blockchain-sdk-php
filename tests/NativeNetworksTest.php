<?php

namespace BlockchainSdk\Tests;

use BlockchainSdk\BlockchainManager;
use BlockchainSdk\Crypto\Base58;
use BlockchainSdk\Crypto\Secp256k1;
use BlockchainSdk\Drivers\Litecoin\LitecoinDriver;
use BlockchainSdk\Drivers\Litecoin\LitecoinTransactionSigner;
use BlockchainSdk\Drivers\Litecoin\LitecoinWalletGenerator;
use BlockchainSdk\Drivers\Xrp\XrpAmount;
use BlockchainSdk\Drivers\Xrp\XrpDriver;
use BlockchainSdk\Drivers\Xrp\XrpTransactionSigner;
use BlockchainSdk\Drivers\Xrp\XrpWalletGenerator;
use BlockchainSdk\DTOs\TransactionReceipt;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class NativeNetworksTest extends TestCase
{
    private function key(int $number = 1): string
    {
        return str_pad((string) $number, 64, '0', STR_PAD_LEFT);
    }

    private function address(int $number = 1): string
    {
        return (new XrpWalletGenerator)->privateKeyToAddress($this->key($number));
    }

    private function client(callable $handler): Client
    {
        return new Client(['handler' => function (RequestInterface $request) use ($handler) {
            $response = $handler($request);

            return Create::promiseFor($response instanceof Response ? $response : new Response(200, [], json_encode($response)));
        }]);
    }

    private function xrpConfig(array &$requests, array $overrides = []): array
    {
        $client = $this->client(function (RequestInterface $request) use (&$requests, $overrides): array {
            $json = json_decode((string) $request->getBody(), true);
            $requests[] = $json;
            $method = $json['method'];
            $params = $json['params'][0];
            $result = match ($method) {
                'account_info' => ['validated' => true, 'account_data' => ['Balance' => '100000000', 'Sequence' => 1, 'Flags' => 0, 'OwnerCount' => 2]],
                'server_info' => ['info' => ['validated_ledger' => ['reserve_base_xrp' => '1', 'reserve_inc_xrp' => '0.2', 'seq' => 100]]],
                'fee' => ['drops' => ['open_ledger_fee' => '10']],
                'account_lines' => ['validated' => true, 'lines' => [['currency' => 'USD', 'account' => $this->address(3), 'balance' => '100', 'limit' => '1000000']]],
                'tx' => ['error' => 'txnNotFound'],
                'submit' => ['engine_result' => 'tesSUCCESS'],
                'account_tx' => ['transactions' => []],
                default => throw new RuntimeException('Unexpected test method '.$method),
            };
            if (isset($overrides[$method])) {
                $result = is_callable($overrides[$method]) ? $overrides[$method]($params, $result) : $overrides[$method];
            }

            return ['result' => $result];
        });

        return ['rpc_nodes' => ['https://xrp.test'], 'http_client' => $client,
            'tokens' => ['USD_ISSUER' => ['currency' => 'USD', 'issuer' => $this->address(3), 'decimals' => 6, 'status' => 'enabled']]];
    }

    public function test_network_registration_and_token_status_preserve_case_sensitive_issuer(): void
    {
        $sdk = new BlockchainManager(['networks' => ['litecoin' => ['type' => 'litecoin'], 'xrp' => ['type' => 'xrp', 'tokens' => [
            'USD_A' => ['currency' => 'USD', 'issuer' => $this->address(3), 'decimals' => 6],
            'USD_B' => ['currency' => 'USD', 'issuer' => $this->address(2), 'decimals' => 6, 'status' => 'disabled'],
        ]]]]);
        $this->assertInstanceOf(LitecoinDriver::class, $sdk->driver('litecoin'));
        $this->assertInstanceOf(XrpDriver::class, $sdk->driver('xrp'));
        $this->assertCount(1, $sdk->getSupportedTokens('xrp', true));
        $this->assertSame('USD_A', $sdk->findToken('xrp', 'USD:'.$this->address(3))['symbol']);
        $this->assertNull($sdk->findToken('xrp', strtolower('USD:'.$this->address(3))));
    }

    public function test_addresses_and_wif_are_specific_to_the_network(): void
    {
        $ltc = new LitecoinWalletGenerator;
        $address = $ltc->privateKeyToAddress($this->key());
        $this->assertSame($address, $ltc->privateKeyToAddress($ltc->exportWif($this->key())));
        $this->assertSame(176, ord(Base58::decodeCheck($ltc->exportWif($this->key()))[0]));
        $this->assertTrue((new LitecoinDriver([]))->validateAddress($address));
        $this->assertFalse((new LitecoinDriver([]))->validateAddress('bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4'));
        $this->assertSame('rBgGZ9tc4him9KBzD8fKFiQz3fSZpaSwMH', $this->address());
        $this->assertTrue((new XrpDriver([]))->validateAddress($this->address()));
        $this->assertFalse((new XrpDriver([]))->validateAddress('1BgGZ9tc4him9KBzD8fKFiQz3fSZpaSwMH'));
    }

    public function test_official_xrpl_native_and_issued_amount_binary_vectors(): void
    {
        // XRPLF/xrpl.js: packages/ripple-binary-codec/test/fixtures/codec-fixtures.json.
        $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/xrpl-codec.json'), true);
        $this->assertSame($fixture['payment']['binary'], (new XrpTransactionSigner)->serialize($fixture['payment']['json']));
        foreach ($fixture['issued_amounts'] as $amount) {
            $this->assertSame($amount['binary'], strtoupper(XrpAmount::issued($amount['json'])));
        }
    }

    public function test_issued_signatures_verify_independently_with_openssl(): void
    {
        $signer = new XrpTransactionSigner;
        $tx = ['TransactionType' => 'Payment', 'Account' => $this->address(), 'Destination' => $this->address(2),
            'Amount' => ['currency' => 'USD', 'issuer' => $this->address(3), 'value' => '12.5'], 'Fee' => '10', 'Sequence' => 1, 'Flags' => 0x80000000];
        $blob = $signer->signTransaction(['private_key' => $this->key(), 'tx' => $tx]);
        $public = Secp256k1::privateKeyToPublicKey($this->key())['compressed'];
        $tx['SigningPubKey'] = $public;
        $offset = strpos($blob, '74', strpos($blob, strtoupper($public)) + strlen($public));
        $signature = hex2bin(substr($blob, $offset + 4, hexdec(substr($blob, $offset + 2, 2)) * 2));
        $der = hex2bin('3036301006072a8648ce3d020106052b8104000a032200'.$public);
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $this->assertSame(1, openssl_verify(hex2bin('53545800'.$signer->serialize($tx)), $signature, $pem, OPENSSL_ALGO_SHA512));
    }

    public function test_litecoin_detection_uses_output_identity_and_confirmations_and_skips_change(): void
    {
        $calls = [];
        $tx = ['txid' => str_repeat('a', 64), 'status' => ['confirmed' => true, 'block_height' => 100],
            'vin' => [['prevout' => ['scriptpubkey_address' => 'sender']]],
            'vout' => [['scriptpubkey_address' => 'recipient', 'value' => 123456789], ['scriptpubkey_address' => 'recipient', 'value' => 2]]];
        $change = $tx;
        $change['vin'][0]['prevout']['scriptpubkey_address'] = 'recipient';
        $client = $this->client(function ($r) use (&$calls, $tx, $change) {
            $calls[] = (string) $r->getUri();

            return str_ends_with($r->getUri()->getPath(), '/height') ? new Response(200, [], '105') : [$tx, $change];
        });
        $events = (new LitecoinDriver(['rpc_nodes' => ['https://ltc.test'], 'http_client' => $client]))->getIncomingTransactions('recipient');
        $this->assertSame([0, 1], array_column($events, 'log_index'));
        $this->assertSame('1.23456789', $events[0]['amount']);
        $this->assertSame('0.00000002', $events[1]['amount']);
        $this->assertSame(6, $events[0]['confirmations']);
        $this->assertCount(2, $calls);
    }

    public function test_provider_failover_does_not_turn_outages_into_zero_balance(): void
    {
        $calls = [];
        $client = $this->client(function ($r) use (&$calls) {
            $calls[] = $r->getUri()->getHost();

            return $r->getUri()->getHost() === 'bad.test' ? new Response(429) : ['chain_stats' => ['funded_txo_sum' => 100, 'spent_txo_sum' => 20]];
        });
        $driver = new LitecoinDriver(['rpc_nodes' => ['https://bad.test', 'https://good.test'], 'http_client' => $client]);
        $this->assertSame('80', $driver->getBalance('address')->balanceRaw);
        $this->assertSame(['bad.test', 'good.test'], $calls);
        $this->expectException(RuntimeException::class);
        (new LitecoinDriver(['rpc_nodes' => ['https://bad.test'], 'http_client' => $client]))->getBalance('address');
    }

    public function test_xrp_native_sweep_retains_reserve_and_reuses_pending_hash(): void
    {
        $requests = [];
        $driver = new XrpDriver($this->xrpConfig($requests));
        $result = $driver->sweep($this->key(), $this->address(2));
        $this->assertSame('98.599990', $result->meta['amount']);
        $this->assertSame('1.400000', $result->meta['retained_balance']);
        $this->assertSame($result->txHash, $driver->sweep($this->key(), $this->address(2))->txHash);
        $submits = array_filter($requests, fn ($r) => $r['method'] === 'submit');
        $this->assertCount(2, $submits);
        $this->assertSame(reset($submits)['params'][0]['tx_blob'], end($submits)['params'][0]['tx_blob']);
    }

    public function test_xrp_sweep_rebroadcasts_the_same_blob_after_a_transport_failure(): void
    {
        $requests = [];
        $attempts = 0;
        $driver = new XrpDriver($this->xrpConfig($requests, ['submit' => function () use (&$attempts): array {
            if (++$attempts === 1) {
                throw new RuntimeException('Temporary transport failure');
            }

            return ['engine_result' => 'tesSUCCESS'];
        }]));

        try {
            $driver->sweep($this->key(), $this->address(2));
            $this->fail('The first submission should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('All configured native-chain providers failed.', $exception->getMessage());
        }

        $this->assertTrue($driver->sweep($this->key(), $this->address(2))->success);
        $submits = array_values(array_filter($requests, fn ($request) => $request['method'] === 'submit'));
        $this->assertCount(2, $submits);
        $this->assertSame($submits[0]['params'][0]['tx_blob'], $submits[1]['params'][0]['tx_blob']);
    }

    public function test_litecoin_discovers_an_older_page_and_checks_known_outputs_directly(): void
    {
        $payment = ['txid' => str_repeat('a', 64), 'status' => ['confirmed' => true, 'block_height' => 100],
            'vout' => [['scriptpubkey_address' => 'recipient', 'value' => 1234]]];
        $firstPage = [];
        for ($i = 1; $i <= 25; $i++) {
            $firstPage[] = ['txid' => str_pad(dechex($i), 64, '0', STR_PAD_LEFT), 'status' => ['confirmed' => true]];
        }
        $cursor = $firstPage[24]['txid'];
        $paths = [];
        $client = $this->client(function (RequestInterface $request) use (&$paths, $firstPage, $cursor, $payment): array|Response {
            $path = $request->getUri()->getPath();
            $paths[] = $path;

            return match ($path) {
                '/address/recipient/txs' => $firstPage,
                '/address/recipient/txs/chain/'.$cursor => [$payment],
                '/tx/'.$payment['txid'] => $payment,
                '/blocks/tip/height' => new Response(200, [], '105'),
            };
        });

        $driver = new LitecoinDriver(['rpc_nodes' => ['https://ltc.test'], 'http_client' => $client]);
        $deposits = $driver->getIncomingTransactions('recipient', limit: 1);
        $this->assertSame([$payment['txid']], array_column($deposits, 'tx_hash'));
        $this->assertSame(6, $deposits[0]['confirmations']);
        $paths = [];
        $this->assertSame($deposits, $driver->getIncomingTransactionOutputs('recipient', $payment['txid']));
        $this->assertSame(['/tx/'.$payment['txid']], $paths);
    }

    public function test_xrp_follows_history_markers_until_it_finds_the_requested_payments(): void
    {
        $payment = ['validated' => true, 'tx' => [
            'hash' => str_repeat('a', 64), 'TransactionType' => 'Payment', 'Account' => $this->address(2), 'Destination' => $this->address(),
        ], 'meta' => ['TransactionResult' => 'tesSUCCESS', 'delivered_amount' => '1000000']];
        $marker = ['ledger' => 100, 'seq' => 20];
        $requests = [];
        $driver = new XrpDriver($this->xrpConfig($requests, ['account_tx' => fn (array $params): array => ($params['marker'] ?? null) === $marker
            ? ['transactions' => [$payment]]
            : ['transactions' => [], 'marker' => $marker]]));
        $this->assertSame([str_repeat('A', 64)], array_column($driver->getIncomingTransactions($this->address(), limit: 1), 'tx_hash'));
        $this->assertCount(2, $requests);
        $this->assertSame($marker, $requests[1]['params'][0]['marker']);
    }

    public function test_xrp_reserve_only_account_does_not_submit(): void
    {
        $requests = [];
        $config = $this->xrpConfig($requests, ['account_info' => ['validated' => true, 'account_data' => ['Balance' => '1000000', 'Sequence' => 1, 'OwnerCount' => 0]]]);
        $result = (new XrpDriver($config))->sweep($this->key(), $this->address(2));
        $this->assertTrue($result->meta['nothing_to_sweep']);
        $this->assertNull($result->txHash);
        $this->assertNotContains('submit', array_column($requests, 'method'));
    }

    public function test_xrp_token_deposit_uses_delivered_value_and_exact_issuer(): void
    {
        $valid = ['validated' => true, 'tx' => ['hash' => str_repeat('a', 64), 'TransactionType' => 'Payment', 'Account' => $this->address(2), 'Destination' => $this->address(), 'Amount' => '999999999', 'ledger_index' => 100],
            'meta' => ['TransactionResult' => 'tesSUCCESS', 'delivered_amount' => ['currency' => 'USD', 'issuer' => $this->address(3), 'value' => '1.25e1']]];
        $wrong = $valid;
        $wrong['meta']['delivered_amount']['issuer'] = $this->address(2);
        $unvalidated = $valid;
        $unvalidated['validated'] = false;
        $requests = [];
        $driver = new XrpDriver($this->xrpConfig($requests, ['account_tx' => ['transactions' => [$valid, $wrong, $unvalidated]]]));
        $events = $driver->getIncomingTransactions($this->address(), 'USD:'.$this->address(3));
        $this->assertCount(1, $events);
        $this->assertSame('12.500000', $events[0]['amount']);
        $this->assertSame('12500000', $events[0]['amount_raw']);
        $this->assertSame([], $driver->getIncomingTransactions($this->address()));
    }

    public function test_token_sweep_subtracts_issuer_fee_before_calculating_delivered_amount(): void
    {
        $requests = [];
        $config = $this->xrpConfig($requests, ['account_info' => fn ($params, $result) => ['validated' => true, 'account_data' => $result['account_data'] + ['TransferRate' => 1100000000]]]);
        $result = (new XrpDriver($config))->sweep($this->key(), $this->address(2), 'USD:'.$this->address(3));
        $this->assertTrue($result->success);
        $this->assertSame('90.909090', $result->meta['amount']);
        $this->assertSame('99.999990', $result->meta['retained_balance']);
        $this->assertCount(1, array_filter($requests, fn ($r) => $r['method'] === 'submit'));
    }

    public function test_token_sweep_refuses_frozen_trust_line_without_broadcasting(): void
    {
        $requests = [];
        $config = $this->xrpConfig($requests, ['account_lines' => fn ($p, $r) => ['validated' => true, 'lines' => [array_merge($r['lines'][0], ['freeze_peer' => true])]]]);
        try {
            (new XrpDriver($config))->sweep($this->key(), $this->address(2), 'USD:'.$this->address(3));
            $this->fail('Frozen token transfer should fail.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('frozen', $error->getMessage());
        }
        $this->assertNotContains('submit', array_column($requests, 'method'));
    }

    public function test_receipts_use_ltc_and_xrp_precision_without_changing_evm_default(): void
    {
        $this->assertSame('0.000010000000', (new TransactionReceipt('hash', 1, '10', '1', true, nativeDecimals: 6))->feeSpent());
        $this->assertSame('0.000010000000', (new TransactionReceipt('hash', 1, '1000', '1', true, nativeDecimals: 8))->feeSpent());
        $this->assertSame('0.000000000000', (new TransactionReceipt('hash', 1, '10', '1', true))->feeSpent());
    }

    public function test_excess_token_precision_is_rejected_instead_of_truncating(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        XrpAmount::raw('0.000000001', 8);
    }

    public function test_litecoin_payment_keeps_requested_amount_and_change_and_uses_confirmed_utxos(): void
    {
        $generator = new LitecoinWalletGenerator;
        $from = $generator->privateKeyToAddress($this->key());
        $to = $generator->privateKeyToAddress($this->key(2));
        $tx = ['version' => 2, 'inputs' => [['txid' => str_repeat('a', 64), 'vout' => 0, 'sequence' => 0xFFFFFFFF]],
            'outputs' => [['amount' => 50000000, 'scriptPubKey' => LitecoinTransactionSigner::addressToScript($to)],
                ['amount' => 49999853, 'scriptPubKey' => LitecoinTransactionSigner::addressToScript($from)]], 'locktime' => 0];
        $hash = LitecoinTransactionSigner::transactionId($tx);
        $requests = [];
        $client = $this->client(function ($request) use (&$requests, $hash) {
            $requests[] = $request;

            return match ($request->getUri()->getPath()) {
                '/address/'.(new LitecoinWalletGenerator)->privateKeyToAddress($this->key()).'/utxo' => [
                    ['txid' => str_repeat('a', 64), 'vout' => 0, 'value' => 100000000, 'status' => ['confirmed' => true]],
                    ['txid' => str_repeat('b', 64), 'vout' => 0, 'value' => 99999999, 'status' => ['confirmed' => false]],
                ],
                '/v1/fees/recommended' => ['halfHourFee' => 1],
                '/tx' => new Response(200, [], $hash),
                default => throw new RuntimeException('Unexpected Litecoin request.'),
            };
        });
        $driver = new LitecoinDriver(['rpc_nodes' => ['https://ltc.test'], 'http_client' => $client]);
        $result = $driver->sendTransaction(['private_key' => $this->key(), 'to' => $to, 'amount' => '0.5']);
        $this->assertSame($hash, $result->txHash);
        $this->assertSame('0.50000000', $result->meta['amount']);
        $this->assertSame('0.49999853', $result->meta['retained_balance']);
        $this->assertStringStartsWith('020000000001', (string) end($requests)->getBody());
    }

    public function test_trust_line_creation_is_explicit_and_locally_signed(): void
    {
        $requests = [];
        $config = $this->xrpConfig($requests, ['account_lines' => ['validated' => true, 'lines' => []]]);
        $result = (new XrpDriver($config))->establishTrustLine($this->key(), 'USD:'.$this->address(3), '100000');
        $this->assertTrue($result->success);
        $submit = array_values(array_filter($requests, fn ($r) => $r['method'] === 'submit'))[0];
        $this->assertStringStartsWith('120014', $submit['params'][0]['tx_blob']);
        $this->assertArrayNotHasKey('secret', $submit['params'][0]);
    }

    public function test_another_asset_cannot_reuse_a_pending_xrp_sweep_sequence(): void
    {
        $requests = [];
        $driver = new XrpDriver($this->xrpConfig($requests));
        $driver->sweep($this->key(), $this->address(2), 'USD:'.$this->address(3));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Another XRP sweep is pending');
        $driver->sweep($this->key(), $this->address(2));
    }

    public function test_token_payment_requires_existing_destination_trust_line(): void
    {
        $requests = [];
        $config = $this->xrpConfig($requests, ['account_lines' => function ($params, $result) {
            return $params['account'] === $this->address(2) ? ['validated' => true, 'lines' => []] : $result;
        }]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('trust line');
        (new XrpDriver($config))->sendTransaction(['private_key' => $this->key(), 'to' => $this->address(2), 'token_contract' => 'USD:'.$this->address(3), 'amount' => '10']);
    }
}
