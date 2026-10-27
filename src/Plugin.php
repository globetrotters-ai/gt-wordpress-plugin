<?php
/**
 * Plugin wiring: builds collaborators and registers hooks.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence;

use Globetrotters\AiPresence\Admin\Notices;
use Globetrotters\AiPresence\Analytics\AiReferrers;
use Globetrotters\AiPresence\Analytics\AiUserAgents;
use Globetrotters\AiPresence\Analytics\CaptureContext;
use Globetrotters\AiPresence\Analytics\EventBuffer;
use Globetrotters\AiPresence\Analytics\EventRecorder;
use Globetrotters\AiPresence\Analytics\FlushScheduler;
use Globetrotters\AiPresence\Analytics\Flusher;
use Globetrotters\AiPresence\Analytics\IngestClient;
use Globetrotters\AiPresence\Analytics\MysqlEventStore;
use Globetrotters\AiPresence\Analytics\MysqlPageViewStore;
use Globetrotters\AiPresence\Analytics\PageCacheExclusion;
use Globetrotters\AiPresence\Analytics\PageViewCounter;
use Globetrotters\AiPresence\Analytics\Schema;
use Globetrotters\AiPresence\Analytics\SiteCapture;
use Globetrotters\AiPresence\Analytics\VendorRanges;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Cache\RefreshScheduler;
use Globetrotters\AiPresence\Client\GtClient;
use Globetrotters\AiPresence\Serving\Breadcrumbs;
use Globetrotters\AiPresence\Serving\HeadInjector;
use Globetrotters\AiPresence\Serving\LinkHeader;
use Globetrotters\AiPresence\Serving\PageViewScript;
use Globetrotters\AiPresence\Serving\RobotsFilter;
use Globetrotters\AiPresence\Serving\Router;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Settings\SettingsPage;
use Globetrotters\AiPresence\Sync\ArtefactSync;
use Globetrotters\AiPresence\Sync\MysqlRefreshLock;
use Globetrotters\AiPresence\Update\UpdateChecker;

/**
 * Single composition root for the plugin.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Options $options;
	private ArtefactCache $cache;
	private ArtefactSync $sync;
	private RefreshScheduler $scheduler;
	private UpdateChecker $updates;
	private Schema $schema;
	private EventBuffer $buffer;
	private EventRecorder $recorder;
	private FlushScheduler $flushes;
	private MysqlPageViewStore $page_views;
	private VendorRanges $ranges;
	private AiReferrers $referrers;
	private AiUserAgents $user_agents;
	private CaptureContext $context;
	private PageCacheExclusion $exclusion;
	private SiteCapture $site_capture;

	private function __construct() {
		$this->options   = new Options();
		$this->cache     = new ArtefactCache();
		$this->sync      = new ArtefactSync( new GtClient(), $this->cache, $this->options, new MysqlRefreshLock() );
		$this->scheduler = new RefreshScheduler( $this->sync, $this->cache, $this->options );
		$this->updates   = new UpdateChecker( new GtClient() );

		$this->schema      = new Schema();
		$this->buffer      = new EventBuffer( new MysqlEventStore() );
		$this->page_views  = new MysqlPageViewStore();
		$this->referrers   = new AiReferrers();
		$this->user_agents = new AiUserAgents();
		$this->exclusion   = new PageCacheExclusion( $this->options );
		$this->ranges      = new VendorRanges( $this->options, $this->referrers, $this->exclusion, $this->user_agents );
		$this->context     = new CaptureContext( $this->options, $this->ranges, null, $this->exclusion );
		// Built before the recorder: capture asks the scheduler for an early flush
		// when the buffer fills, and the scheduler needs nothing from capture.
		$this->flushes      = new FlushScheduler(
			new Flusher( $this->buffer, new IngestClient(), $this->options, $this->page_views, $this->context ),
			$this->options
		);
		$this->recorder     = new EventRecorder( $this->buffer, $this->options, $this->flushes, $this->context );
		$this->site_capture = new SiteCapture( $this->buffer, $this->options, $this->flushes, $this->ranges, $this->context );
	}

	/**
	 * Shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all runtime hooks.
	 */
	public function boot(): void {
		( new Router( $this->cache, $this->options, $this->recorder, new PageViewCounter( $this->page_views, $this->referrers ) ) )->register();
		( new HeadInjector( $this->cache ) )->register();
		( new LinkHeader( $this->cache ) )->register();
		( new Breadcrumbs( $this->options, $this->cache ) )->register();
		( new RobotsFilter( $this->options, $this->cache, $this->user_agents ) )->register();
		// Hooks nothing at all unless page-view counting is on.
		$page_view_script = new PageViewScript( $this->options );
		$page_view_script->register();
		$this->scheduler->register();
		$this->flushes->register();
		// Site-wide bot capture on shutdown; a no-op unless it is on.
		$this->site_capture->register();
		// The vendor range list and the AI referrer list it carries, at most
		// once a day, from whichever tick runs first: the hourly refresh (only
		// scheduled while connected) or the flush (only scheduled while
		// reporting). Independent of the bundle.
		add_action( RefreshScheduler::HOOK, array( $this->ranges, 'refresh_if_due' ), 20 );
		add_action( FlushScheduler::HOOK, array( $this->ranges, 'refresh_if_due' ), 20 );
		// The opt-in page-cache exclusion follows the list that fetch may just
		// have replaced, and the page cache detected now (a cache swapped since
		// is re-applied to). A no-op when nothing changed.
		add_action( RefreshScheduler::HOOK, array( $this, 'sync_page_cache_exclusion' ), 21 );
		add_action( FlushScheduler::HOOK, array( $this, 'sync_page_cache_exclusion' ), 21 );
		// Switching it (or site capture) on or off takes effect on save, outside
		// is_admin() so a ``wp option update`` counts too.
		add_action( 'update_option_' . Options::SETTINGS, array( $this, 'sync_page_cache_exclusion' ), 20 );
		// WP Rocket's filter, while the list is applied there.
		$this->exclusion->register();
		// Creating a missing event asks WordPress for its registered schedules,
		// which runs the ``cron_schedules`` filter — and both of our callbacks
		// build a translated label. Doing that here, on ``plugins_loaded``, loads
		// the text domain before ``init``, which WordPress 6.7+ reports as
		// ``_load_textdomain_just_in_time``. It only shows up on the one request
		// that actually creates the event, which is what makes it easy to miss.
		//
		// Priority 9, not the default: core hooks ``wp_cron`` to ``init`` at 10,
		// and registers it during bootstrap — so at equal priority it would run
		// first and the just-created (already due) events would wait for the next
		// request to spawn. Running before it keeps the first refresh and flush on
		// the same request they happened on when this ran at ``plugins_loaded``.
		add_action( 'init', array( $this, 'ensure_scheduled' ), 9 );
		// Registered unconditionally: the update-check filter also fires from
		// WP-Cron's background check, where is_admin() is false.
		$this->updates->register();

		// Create the buffer table the moment reporting is switched on. Registered
		// outside is_admin() so it also fires for a WP-CLI ``wp option update``,
		// which is how a managed install may well be configured.
		add_action( 'update_option_' . Options::SETTINGS, array( $this, 'ensure_reporting_storage' ) );
		// Clearing the website URL, or pointing it at another destination, stops
		// the old presence at once. Outside is_admin() for the same reason: a
		// ``wp option update`` must disconnect as surely as the settings screen.
		add_action( 'update_option_' . Options::SETTINGS, array( $this->sync, 'forget_previous_source' ), 10, 2 );
		// A schema bump reaches an auto-updated install that nobody logs into on
		// its next flush, not only on its next admin page load. Priority 9, so
		// the table is there before the flush at 10 looks for it.
		add_action( FlushScheduler::HOOK, array( $this, 'ensure_reporting_storage' ), 9 );
		add_action( FlushScheduler::HOOK_NOW, array( $this, 'ensure_reporting_storage' ), 9 );

		if ( is_admin() ) {
			// Safety net for the cases the save hook misses: a schema bump arriving
			// by auto-update (which never fires the activation hook), or a table
			// dropped out from under a configured install. Cheap: one autoloaded
			// option read when already current, and nothing at all when reporting
			// is off.
			add_action( 'admin_init', array( $this, 'ensure_reporting_storage' ) );
			// The suggested privacy-policy paragraph, while counting is on.
			add_action( 'admin_init', array( $page_view_script, 'add_privacy_policy_content' ) );
			( new SettingsPage( $this->options, $this->sync, $this->scheduler, $this->cache, $this->updates, $this->buffer, $this->flushes, $this->ranges, $this->referrers, $this->exclusion, $this->context ) )->register();
			( new Notices( $this->options ) )->register();
		}
	}

	/**
	 * Apply, re-apply or remove the page-cache exclusion
	 * ({@see PageCacheExclusion::sync()}). Never throws.
	 */
	public function sync_page_cache_exclusion(): void {
		$this->exclusion->sync( $this->context->page_cache() );
	}

	/**
	 * Create the recurring events this install needs, if they aren't there yet.
	 *
	 * Runs on ``init`` rather than from {@see boot()} — see the note there. Both
	 * schedulers no-op when the install has nothing to schedule.
	 */
	public function ensure_scheduled(): void {
		$this->scheduler->ensure_scheduled();
		$this->flushes->ensure_scheduled();
	}

	/**
	 * Install the buffer table, but only for an install that will actually use it.
	 *
	 * Capture is gated on ingest credentials being present, so a site that never
	 * pastes them would otherwise carry an empty table forever. Creating it on
	 * demand keeps the plugin's footprint to what the site is really doing.
	 */
	public function ensure_reporting_storage(): void {
		if ( ! $this->options->is_reporting_configured() ) {
			return;
		}
		$this->schema->ensure_installed();
	}

	/**
	 * Activation: schedule the refresh (its first run is due immediately).
	 */
	public static function activate(): void {
		$plugin = self::instance();
		// Only when this install already reports — a deactivate/reactivate cycle
		// must get its buffer table back, a first-time install has no use for one.
		$plugin->ensure_reporting_storage();
		// Register the cron callback and custom schedules first: boot() (which
		// normally wires them) hasn't run in the activation request. The refresh
		// event itself always recurs hourly, so this no longer decides its
		// recurrence, but the flush scheduler's custom interval still needs it.
		$plugin->scheduler->register();
		$plugin->scheduler->ensure_scheduled();
		$plugin->flushes->register();
		$plugin->flushes->ensure_scheduled();
	}

	/**
	 * Deactivation: tear down the scheduled events.
	 *
	 * The buffer table survives — a deactivate/reactivate cycle should not throw
	 * away captured-but-unflushed traffic. It is dropped on uninstall.
	 */
	public static function deactivate(): void {
		// Leave the page cache exactly as it was before this plugin touched it.
		self::instance()->exclusion->remove_all();
		self::instance()->scheduler->unschedule();
		self::instance()->flushes->unschedule();
	}
}
