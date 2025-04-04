<?php

declare(strict_types=1);

namespace Drupal\Tests\Core\Utility;

use Drupal\Core\GeneratedUrl;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Utility\UnroutedUrlAssembler;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\Core\Utility\UnroutedUrlAssembler
 * @group Utility
 */
class UnroutedUrlAssemblerTest extends UnitTestCase {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * The tested unrouted URL assembler.
   *
   * @var \Drupal\Core\Utility\UnroutedUrlAssembler
   */
  protected $unroutedUrlAssembler;

  /**
   * The mocked outbound path processor.
   *
   * @var \Drupal\Core\PathProcessor\OutboundPathProcessorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $pathProcessor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->requestStack = new RequestStack();
    $this->pathProcessor = $this->createMock('Drupal\Core\PathProcessor\OutboundPathProcessorInterface');
    $this->unroutedUrlAssembler = new UnroutedUrlAssembler($this->requestStack, $this->pathProcessor);
  }

  /**
   * @covers ::assemble
   */
  public function testAssembleWithNeitherExternalNorDomainLocalUri(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->unroutedUrlAssembler->assemble('wrong-url');
  }

  /**
   * @covers ::assemble
   */
  public function testAssembleWithLeadingSlash(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->unroutedUrlAssembler->assemble('/drupal.org');
  }

  /**
   * @covers ::assemble
   * @covers ::buildExternalUrl
   *
   * @dataProvider providerTestAssembleWithExternalUrl
   */
  public function testAssembleWithExternalUrl($uri, array $options, $expected): void {
    $this->setupRequestStack(FALSE);
    $this->assertEquals($expected, $this->unroutedUrlAssembler->assemble($uri, $options));
    $generated_url = $this->unroutedUrlAssembler->assemble($uri, $options, TRUE);
    $this->assertEquals($expected, $generated_url->getGeneratedUrl());
    $this->assertInstanceOf('\Drupal\Core\Render\BubbleableMetadata', $generated_url);
  }

  /**
   * Provides test data for testAssembleWithExternalUrl.
   */
  public static function providerTestAssembleWithExternalUrl() {
    return [
      ['http://example.com/test', [], 'http://example.com/test'],
      ['http://example.com/test', ['fragment' => 'example'], 'http://example.com/test#example'],
      ['http://example.com/test', ['fragment' => 'example'], 'http://example.com/test#example'],
      ['http://example.com/test', ['query' => ['foo' => 'bar']], 'http://example.com/test?foo=bar'],
      ['http://example.com/test', ['https' => TRUE], 'https://example.com/test'],
      ['https://example.com/test', ['https' => FALSE], 'http://example.com/test'],
      ['https://example.com/test?foo=1#bar', [], 'https://example.com/test?foo=1#bar'],
      'override-query' => ['https://example.com/test?foo=1#bar', ['query' => ['foo' => 2]], 'https://example.com/test?foo=2#bar'],
      'override-query-merge' => ['https://example.com/test?foo=1#bar', ['query' => ['bar' => 2]], 'https://example.com/test?foo=1&bar=2#bar'],
      'override-deep-query-merge' => ['https://example.com/test?foo=1#bar', ['query' => ['bar' => ['baz' => 'foo']]], 'https://example.com/test?foo=1&bar%5Bbaz%5D=foo#bar'],
      'override-deep-query-merge-int-ket' => ['https://example.com/test?120=1', ['query' => ['bar' => ['baz' => 'foo']]], 'https://example.com/test?120=1&bar%5Bbaz%5D=foo'],
      'override-fragment' => ['https://example.com/test?foo=1#bar', ['fragment' => 'baz'], 'https://example.com/test?foo=1#baz'],
      ['//www.drupal.org', [], '//www.drupal.org'],
    ];
  }

  /**
   * @covers ::assemble
   * @covers ::buildLocalUrl
   *
   * @dataProvider providerTestAssembleWithLocalUri
   */
  public function testAssembleWithLocalUri($uri, array $options, $subdir, $expected): void {
    $this->setupRequestStack($subdir);

    $this->assertEquals($expected, $this->unroutedUrlAssembler->assemble($uri, $options));
  }

  /**
   * @return array
   */
  public static function providerTestAssembleWithLocalUri() {
    return [
      ['base:example', [], FALSE, '/example'],
      ['base:example', ['query' => ['foo' => 'bar']], FALSE, '/example?foo=bar'],
      ['base:example', ['query' => ['foo' => '"bar"']], FALSE, '/example?foo=%22bar%22'],
      ['base:example', ['query' => ['foo' => '"bar"', 'zoo' => 'baz']], FALSE, '/example?foo=%22bar%22&zoo=baz'],
      ['base:example', ['fragment' => 'example'], FALSE, '/example#example'],
      ['base:example', [], TRUE, '/subdir/example'],
      ['base:example', ['query' => ['foo' => 'bar']], TRUE, '/subdir/example?foo=bar'],
      ['base:example', ['fragment' => 'example'], TRUE, '/subdir/example#example'],
      ['base:/drupal.org', [], FALSE, '/drupal.org'],
    ];
  }

  /**
   * @covers ::assemble
   */
  public function testAssembleWithNotEnabledProcessing(): void {
    $this->setupRequestStack(FALSE);
    $this->pathProcessor->expects($this->never())
      ->method('processOutbound');
    $result = $this->unroutedUrlAssembler->assemble('base:test-uri', []);
    $this->assertEquals('/test-uri', $result);
  }

  /**
   * @covers ::assemble
   */
  public function testAssembleWithEnabledProcessing(): void {
    $this->setupRequestStack(FALSE);
    $this->pathProcessor->expects($this->exactly(2))
      ->method('processOutbound')
      ->willReturnCallback(function ($path, &$options = [], ?Request $request = NULL, ?BubbleableMetadata $bubbleable_metadata = NULL) {
        if ($bubbleable_metadata) {
          $bubbleable_metadata->setCacheContexts(['some-cache-context']);
        }
        return '/test-other-uri';
      });

    $result = $this->unroutedUrlAssembler->assemble('base:test-uri', ['path_processing' => TRUE]);
    $this->assertEquals('/test-other-uri', $result);

    $result = $this->unroutedUrlAssembler->assemble('base:test-uri', ['path_processing' => TRUE], TRUE);
    $expected_generated_url = new GeneratedUrl();
    $expected_generated_url->setGeneratedUrl('/test-other-uri')
      ->setCacheContexts(['some-cache-context']);
    $this->assertEquals($expected_generated_url, $result);
  }

  /**
   * @covers ::assemble
   */
  public function testAssembleWithStartingSlashEnabledProcessing(): void {
    $this->setupRequestStack(FALSE);
    $this->pathProcessor->expects($this->exactly(2))
      ->method('processOutbound')
      ->with('/test-uri', $this->anything(), $this->anything(), $this->anything())
      ->willReturnCallback(function ($path, &$options = [], ?Request $request = NULL, ?BubbleableMetadata $bubbleable_metadata = NULL) {
        $bubbleable_metadata?->setCacheContexts(['some-cache-context']);
        return '/test-other-uri';
      });

    $result = $this->unroutedUrlAssembler->assemble('base:/test-uri', ['path_processing' => TRUE]);
    $this->assertEquals('/test-other-uri', $result);

    $result = $this->unroutedUrlAssembler->assemble('base:/test-uri', ['path_processing' => TRUE], TRUE);
    $expected_generated_url = new GeneratedUrl();
    $expected_generated_url->setGeneratedUrl('/test-other-uri')
      ->setCacheContexts(['some-cache-context']);
    $this->assertEquals($expected_generated_url, $result);
  }

  /**
   * Setups the request stack for a given subdir.
   *
   * @param bool $subdir
   *   TRUE to use a subdir.
   */
  protected function setupRequestStack($subdir): void {
    $server = [];
    if ($subdir) {
      // Setup a fake request which looks like a Drupal installed under the
      // subdir "subdir" on the domain www.example.com.
      // To reproduce the values install Drupal like that and use a debugger.
      $server = [
        'SCRIPT_NAME' => '/subdir/index.php',
        'SCRIPT_FILENAME' => $this->root . '/index.php',
        'SERVER_NAME' => 'www.example.com',
      ];
      $request = Request::create('/subdir/');
    }
    else {
      $request = Request::create('/');
    }
    $request->server->add($server);
    $this->requestStack->push($request);
  }

}
