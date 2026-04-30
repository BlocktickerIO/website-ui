<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_AIBlog {

    private static $topics = array(
        'ai-daily-report' => array(
            'title'    => 'Daily Crypto Intelligence Report — %s',
            'prompt'   => "You are an institutional crypto research analyst. Using ONLY the live market data provided below, write a hedge-fund-style daily intelligence report.\n\n"
                        . "REQUIRED STRUCTURE (use exact H2 headings):\n"
                        . "## Market Overview\n"
                        . "Open with the regime: expansion, contraction, or consolidation. Cite total crypto market cap and 24h change. State the Fear & Greed reading.\n\n"
                        . "## Top 5 Coins Breakdown\n"
                        . "For each of the top 5 coins by market cap (BTC, ETH, and the next 3 from the data): state exact price, 24h %, 7d %, market cap. Interpret each move in one sentence — no speculation, only what the data shows.\n\n"
                        . "## Top 5 Gainers (24h)\n"
                        . "Identify the 5 largest positive 24h movers from the top-15 data provided. For each: symbol, name, price, 24h %. Group them by likely cause (category rotation, news-driven, or idiosyncratic).\n\n"
                        . "## Liquidity & Volume\n"
                        . "Analyze 24h volume for BTC and ETH relative to their market caps (turnover ratio). Flag any coin in the top 15 with unusually high volume-to-mcap ratio as a liquidity outlier.\n\n"
                        . "## Support & Resistance Levels\n"
                        . "For BTC and ETH: name one specific support (recent low) and one specific resistance (recent high) using the 7d % and current price. Be numeric.\n\n"
                        . "## Cross-Market Signal\n"
                        . "Connect today's crypto regime to USD strength. Use EUR/USD and USD/JPY from the forex data. Is the dollar firming or easing, and how does that align with crypto direction?\n\n"
                        . "## 24–72H Outlook\n"
                        . "State the base case in one sentence. Name 2 specific levels to watch (price or index value) that would invalidate or confirm the base case.\n\n"
                        . "## Key Risks\n"
                        . "List 3 bullet-point risks. Each bullet references a specific data point or news headline from the provided context.\n\n"
                        . "## Final Summary\n"
                        . "Two sentences. Actionable. Institutional.\n\n"
                        . "STYLE: Use institutional financial language (liquidity, regime, positioning, structure, turnover). No retail slang. No hedge words ('could potentially', 'may possibly'). No disclaimers. Everything derived from the data — no invented prices or outside context. Target 900-1200 words.",
            'category' => 'Market Analysis',
            'tags'     => array( 'Daily Report', 'Institutional', 'Market Intelligence' ),
        ),
        'bitcoin-analysis' => array(
            'title'    => 'Bitcoin Price Analysis: %s',
            'prompt'   => 'Write a professional 900-1100 word Bitcoin market analysis using the live data provided below. Required structure: (1) Open with the exact 24h price move and what it means in context of the 7d trend. (2) "What is driving this" section - tie the move to specific news headlines from the provided list or note no catalyst. (3) "Technical picture" section - call out specific support/resistance levels as exact dollar figures, referencing the 7d range. (4) "Market structure" section - incorporate BTC dominance, global market cap move, and Fear & Greed. (5) "What to watch" section with 3 specific numeric thresholds and 2-3 actionable retail takeaways. Analytical, confident voice. H2 subheadings only. No generic openings, no hedge words.',
            'category' => 'Market Analysis',
            'tags'     => array( 'Bitcoin', 'BTC', 'Price Analysis' ),
        ),
        'ethereum-update' => array(
            'title'    => 'Ethereum Market Update: %s',
            'prompt'   => 'Write a professional 800-1000 word Ethereum-focused market update using the live data below. Required structure: (1) Lead with ETH 24h move and ETH/BTC ratio context (are altcoins leading or lagging today?). (2) "Ethereum fundamentals" - incorporate L2 activity narrative, staking dynamics, DeFi TVL context where relevant. (3) "What moved it" - cite specific news headlines from the provided list. (4) "Technical levels" - name specific dollar prices as support/resistance. (5) "Outlook" with 2-3 actionable takeaways. Professional analyst voice, H2 headings only.',
            'category' => 'Market Analysis',
            'tags'     => array( 'Ethereum', 'ETH', 'DeFi' ),
        ),
        'forex-weekly' => array(
            'title'    => 'Forex Weekly: Major Pairs Review — %s',
            'prompt'   => 'Write an 800-1000 word forex weekly review using the live rates below. Required structure: (1) Open with the day\'s most active pair and its exact move. (2) "Dollar strength check" - discuss EUR/USD and USD/JPY moves together as a dollar-strength signal. (3) For each of EUR/USD, GBP/USD, USD/JPY: state the exact rate, the 24h move, and name the relevant central bank or upcoming economic event. (4) "Crypto crossover" - one short paragraph noting how dollar moves correlate with BTC today (use the F&G index and BTC 24h move). (5) "Watch this week" - list 2-3 specific economic events or thresholds. H2 headings. Analytical voice.',
            'category' => 'Forex News',
            'tags'     => array( 'Forex', 'EUR/USD', 'Fed' ),
        ),
        'crypto-roundup' => array(
            'title'    => 'Daily Crypto Roundup: %s',
            'prompt'   => 'Write a 900-1100 word daily crypto roundup using the live data below. Required structure: (1) Lead paragraph with the day\'s single most important development - the top mover, a major news item, or a notable correlation. (2) "Top movers" - walk through the day\'s top gainer and top loser with exact percentages and why they moved (cite specific news if available). (3) "Bitcoin and Ethereum" - state their exact prices and moves, note the BTC/ETH dynamic. (4) "News that matters" - summarize 2-3 specific headlines from the provided list with BlockTicker context on why each matters. (5) "The setup" - 2-3 actionable takeaways for retail traders with specific price levels or conditions. Professional analyst voice, H2 headings.',
            'category' => 'Crypto News',
            'tags'     => array( 'Crypto', 'Altcoins', 'DeFi' ),
        ),
        'altcoin-spotlight' => array(
            'title'    => 'Altcoin Spotlight: Top Movers — %s',
            'prompt'   => 'Write a 700-900 word altcoin spotlight using the top movers in the data below. For each of the 3 largest-percentage-movers (outside BTC/ETH): (1) name the coin, current exact price, and 24h %, (2) explain what the project does in two sentences, (3) explain why it likely moved today - cite a news headline if one matches, else note it\'s a market-wide flow move, (4) call out a specific price level to watch. Close with a short "Takeaway" section connecting the moves to a broader market theme. H2 per coin. No fluff.',
            'category' => 'Altcoins',
            'tags'     => array( 'Altcoins', 'Crypto', 'Trading' ),
        ),
        'defi-update' => array(
            'title'    => 'DeFi & Web3 Weekly: %s',
            'prompt'   => 'Write a 700-900 word DeFi and Web3 update using the live data below. Required structure: (1) Lead with a specific recent DeFi or Web3 headline from the news list. (2) "TVL context" - incorporate ETH price move and global market cap as backdrop for TVL trends. (3) "What\'s happening in Web3" - cite 2-3 specific news headlines about protocol launches, governance, or NFT trends from the list. (4) "Levels and flows" - reference specific data like DeFi-related coin prices from the top 15 above (UNI, AAVE, MKR, LINK if present). (5) "Watch this" - 2-3 actionable takeaways. H2 headings.',
            'category' => 'DeFi & Web3',
            'tags'     => array( 'DeFi', 'Web3', 'NFT' ),
        ),
        'cross-market-brief' => array(
            'title'    => 'Cross-Market Brief: Crypto + Forex — %s',
            'prompt'   => 'Write a 700-900 word cross-market briefing that connects today\'s crypto moves to today\'s forex moves using the data below. This is BlockTicker\'s differentiator - most sites silo these. Required structure: (1) Lead with the most notable cross-market relationship today (e.g. "Dollar strength of X% corresponds with BTC weakness of Y%"). (2) "Dollar check" - EUR/USD, USD/JPY exact moves and what they signal. (3) "Risk-on or risk-off?" - combine F&G index reading, USD/JPY direction, and BTC 24h move into a single market-regime verdict. (4) "What this means for crypto" - specific implications for BTC, ETH, and altcoins. (5) "Trading desk view" - 2-3 actionable takeaways. H2 headings.',
            'category' => 'Market Analysis',
            'tags'     => array( 'Macro', 'Forex', 'Bitcoin' ),
        ),
        'education' => array(
            'title'    => 'Beginner Guide: %s',
            'prompt'   => 'Write a professional 800-word educational article for crypto beginners. Pick ONE topic from: how wallets work, understanding market cap, reading candlestick charts, what is staking, how DEXs work, understanding gas fees, or what are stablecoins. Clear, friendly tone with examples. Reference today\'s market data above where relevant to ground the concept in reality (e.g. if explaining market cap, use the actual top coin market caps from the data). Format with H2 subheadings.',
            'category' => 'Education',
            'tags'     => array( 'Education', 'Beginner', 'Guide' ),
        ),
    );

    public static function init() {
        add_action( 'bt_daily_ai_post', array( __CLASS__, 'generate_daily_post' ) );
        // v112.0: on-demand per-asset analysis
        add_action( 'wp_ajax_bt_generate_asset_analysis',        array( __CLASS__, 'ajax_generate_asset_analysis' ) );
        add_action( 'wp_ajax_nopriv_bt_generate_asset_analysis', array( __CLASS__, 'ajax_generate_asset_analysis' ) );
        add_shortcode( 'bt_ai_analysis_v2', array( __CLASS__, 'sc_ai_analysis_v2' ) );
    }

    public static function setup() {
        // Schedule daily AI post at 8am UTC
        $next_8am = strtotime( 'tomorrow 08:00:00 UTC' );
        if ( ! wp_next_scheduled( 'bt_daily_ai_post' ) ) {
            wp_schedule_event( $next_8am, 'daily', 'bt_daily_ai_post' );
        }

        // Create required categories
        $cats = array( 'Market Analysis', 'Crypto News', 'Forex News', 'Altcoins', 'DeFi & Web3', 'Education', 'Bitcoin', 'Ethereum', 'Trading Signals', 'Regulation', 'Press Releases' );
        foreach ( $cats as $cat ) {
            if ( ! term_exists( $cat, 'category' ) ) wp_insert_term( $cat, 'category' );
        }

        // Generate 30 days of seed articles on first setup
        if ( ! get_option( 'bt_seed_articles_done' ) ) {
            self::bulk_generate_seed_articles();
            update_option( 'bt_seed_articles_done', true );
        }

        return array( 'success' => true, 'message' => 'AI Blog: Daily auto-post scheduled. 30 seed articles generated. 11 categories created.' );
    }

    /**
     * Generate 30 backdated seed articles for content authority.
     * Each article is dated progressively over the past 30 days.
     */
    public static function bulk_generate_seed_articles() {
        $site_name = get_option( 'bt_site_name', 'BlockTicker' );
        $home_url  = home_url( '/' );

        $seed_articles = array(
            array( 'title' => 'What Is Bitcoin? A Complete Beginner\'s Guide for 2026', 'category' => 'Education', 'tags' => array('Bitcoin','Beginner','Guide'), 'content' => '<h2>Understanding Bitcoin</h2><p>Bitcoin (BTC) is the world\'s first decentralized digital currency, created in 2009 by an anonymous developer known as Satoshi Nakamoto. Unlike traditional currencies controlled by central banks, Bitcoin operates on a peer-to-peer network using blockchain technology — a distributed public ledger that records every transaction ever made.</p><h2>How Does Bitcoin Work?</h2><p>Every Bitcoin transaction is verified by network nodes through cryptography and recorded on the blockchain. This process, known as mining, involves powerful computers solving complex mathematical puzzles. Miners who successfully validate transactions are rewarded with newly created bitcoins, creating a transparent and secure monetary system.</p><h2>Why Does Bitcoin Have Value?</h2><p>Bitcoin\'s value comes from several factors: limited supply (only 21 million will ever exist), decentralization (no single entity controls it), security (the blockchain has never been hacked), and growing adoption by institutions and governments worldwide. In 2026, Bitcoin is widely recognized as digital gold and a store of value.</p><h2>How to Buy Bitcoin</h2><p>You can purchase Bitcoin through cryptocurrency exchanges like Coinbase, Binance, or Kraken. Most exchanges accept bank transfers, credit cards, and other payment methods. After purchasing, you can store your Bitcoin in a digital wallet — either a software wallet on your phone or a hardware wallet for maximum security.</p>' ),

            array( 'title' => 'Ethereum vs Bitcoin: Key Differences Every Investor Should Know', 'category' => 'Education', 'tags' => array('Ethereum','Bitcoin','Comparison'), 'content' => '<h2>Two Different Visions</h2><p>While Bitcoin was designed as a digital currency and store of value, Ethereum was built as a programmable blockchain platform that enables smart contracts and decentralized applications (dApps). This fundamental difference shapes how each network is used and valued.</p><h2>Smart Contracts: Ethereum\'s Superpower</h2><p>Ethereum introduced smart contracts — self-executing agreements written in code that run automatically when conditions are met. This innovation spawned entire industries: decentralized finance (DeFi), non-fungible tokens (NFTs), and decentralized autonomous organizations (DAOs).</p><h2>Technical Differences</h2><p>Bitcoin uses Proof of Work (PoW) consensus, while Ethereum transitioned to Proof of Stake (PoS) in 2022, reducing its energy consumption by over 99%. Ethereum processes transactions faster (12 seconds vs Bitcoin\'s 10 minutes) and supports a broader range of use cases through its EVM (Ethereum Virtual Machine).</p><h2>Investment Perspective</h2><p>Bitcoin is often viewed as "digital gold" — a hedge against inflation with a fixed supply cap. Ethereum is more like "digital oil" — powering the decentralized computing ecosystem. Both play important roles in a diversified crypto portfolio, but they serve very different purposes.</p>' ),

            array( 'title' => 'How to Read Crypto Charts: Candlestick Patterns Explained', 'category' => 'Education', 'tags' => array('Trading','Charts','Technical Analysis'), 'content' => '<h2>Understanding Candlestick Charts</h2><p>Candlestick charts are the most popular way to visualize price movements in crypto trading. Each "candle" represents a specific time period and shows four key data points: the opening price, closing price, highest price, and lowest price during that period.</p><h2>Bullish vs Bearish Candles</h2><p>Green (or white) candles indicate the price closed higher than it opened — bullish movement. Red (or black) candles mean the price closed lower — bearish movement. The "body" shows the range between open and close, while the "wicks" (thin lines) show the highest and lowest points reached.</p><h2>Key Patterns to Watch</h2><p>Doji candles (tiny body, long wicks) signal market indecision and potential reversal. Hammer patterns (small body at top, long lower wick) at the bottom of a downtrend often signal a bullish reversal. Engulfing patterns, where one candle completely covers the previous one, indicate strong momentum shifts.</p><h2>Combining with Indicators</h2><p>Candlestick patterns are most effective when combined with technical indicators like RSI (Relative Strength Index), MACD (Moving Average Convergence Divergence), and volume analysis. Always look for confirmation before making trading decisions based on chart patterns.</p>' ),

            array( 'title' => 'DeFi Explained: How Decentralized Finance Is Changing Banking', 'category' => 'DeFi & Web3', 'tags' => array('DeFi','Finance','Blockchain'), 'content' => '<h2>What Is DeFi?</h2><p>Decentralized Finance (DeFi) refers to financial services built on blockchain technology that operate without traditional intermediaries like banks, brokerages, or insurance companies. Instead of relying on centralized institutions, DeFi uses smart contracts on platforms like Ethereum, Solana, and Avalanche to provide lending, borrowing, trading, and insurance services.</p><h2>Popular DeFi Applications</h2><p>Decentralized exchanges (DEXs) like Uniswap and Curve allow users to trade tokens directly from their wallets. Lending platforms like Aave and Compound let users earn interest by lending their crypto assets or borrow against their holdings. Yield farming and liquidity mining offer additional earning opportunities for crypto holders.</p><h2>Benefits and Risks</h2><p>DeFi offers 24/7 access, no credit checks, global availability, and full transparency through on-chain transactions. However, risks include smart contract vulnerabilities, impermanent loss in liquidity pools, regulatory uncertainty, and the complexity of managing private keys. Always start with small amounts and thoroughly research protocols before committing funds.</p>' ),

            array( 'title' => 'Top 5 Crypto Wallets for Secure Storage in 2026', 'category' => 'Education', 'tags' => array('Wallets','Security','Guide'), 'content' => '<h2>Hot Wallets vs Cold Wallets</h2><p>Crypto wallets come in two main types: hot wallets (connected to the internet, convenient for daily use) and cold wallets (offline hardware devices, maximum security for long-term storage). The best approach is using both — a hot wallet for active trading and a cold wallet for your long-term holdings.</p><h2>Recommended Wallets</h2><p>For hardware wallets, Ledger Nano X and Trezor Model T remain the industry standard, supporting thousands of cryptocurrencies with military-grade security. For software wallets, MetaMask leads for Ethereum and EVM-compatible chains, while Phantom excels for Solana. Trust Wallet by Binance offers broad multi-chain support and a built-in DEX.</p><h2>Security Best Practices</h2><p>Never share your seed phrase with anyone. Store your recovery phrase offline in multiple secure locations. Enable two-factor authentication (2FA) on all exchange accounts. Verify wallet addresses carefully before sending transactions — crypto transfers are irreversible. Consider using a dedicated device for crypto transactions.</p>' ),

            array( 'title' => 'Understanding Market Cap: Why Price Alone Doesn\'t Tell the Full Story', 'category' => 'Education', 'tags' => array('Market Cap','Investing','Basics'), 'content' => '<h2>What Is Market Capitalization?</h2><p>Market capitalization (market cap) is calculated by multiplying a cryptocurrency\'s current price by its total circulating supply. It\'s a more meaningful metric than price alone because it reflects the total value of all coins in circulation, giving investors a better sense of a project\'s size and dominance.</p><h2>Market Cap Categories</h2><p>Large-cap cryptos (over $10 billion) like Bitcoin and Ethereum are generally considered lower risk with established track records. Mid-cap coins ($1-10 billion) offer growth potential with moderate risk. Small-cap tokens (under $1 billion) carry higher risk but potentially higher returns. Micro-caps can be extremely volatile and speculative.</p><h2>Why It Matters for Investing</h2><p>A coin priced at $0.01 with 100 billion tokens in circulation has the same market cap as a $100 coin with 10 million tokens. Understanding this prevents the common mistake of thinking "cheap" coins have more room to grow. Always compare market caps, not just prices, when evaluating investment opportunities.</p>' ),

            array( 'title' => 'Forex Trading Basics: How Currency Markets Work', 'category' => 'Forex News', 'tags' => array('Forex','Trading','Beginner'), 'content' => '<h2>The Forex Market</h2><p>The foreign exchange (forex) market is the largest and most liquid financial market in the world, with daily trading volume exceeding $7.5 trillion. Unlike stock markets, forex operates 24 hours a day, five days a week, across global financial centers from Sydney to New York.</p><h2>Currency Pairs Explained</h2><p>Forex trading always involves two currencies — a base currency and a quote currency. Major pairs like EUR/USD, GBP/USD, and USD/JPY involve the US dollar. Cross pairs like EUR/GBP exclude the dollar. When EUR/USD is quoted at 1.0845, it means 1 Euro equals 1.0845 US Dollars.</p><h2>Key Drivers of Currency Prices</h2><p>Central bank interest rate decisions are the primary driver — higher rates generally strengthen a currency. Economic indicators like GDP, employment data, and inflation reports also move markets. Geopolitical events, trade balances, and market sentiment add additional layers of complexity to forex analysis.</p><h2>Getting Started</h2><p>Begin with a demo account to practice without risking real money. Learn to read charts, understand leverage (which amplifies both gains and losses), and develop a risk management strategy. Never risk more than 1-2% of your account on a single trade.</p>' ),

            array( 'title' => 'Bitcoin Price Analysis: Support and Resistance Levels to Watch', 'category' => 'Market Analysis', 'tags' => array('Bitcoin','Price Analysis','Technical'), 'content' => '<h2>Current Market Structure</h2><p>Bitcoin continues to trade within a consolidation range as markets digest the impact of global monetary policy shifts. The key battle between bulls and bears plays out at critical support and resistance levels that have historically defined major price movements.</p><h2>Key Support Levels</h2><p>The $60,000-$62,000 zone represents strong support, having served as resistance during the previous cycle before flipping to support. Below that, $55,000 aligns with the 200-day moving average and represents a deeper correction target. A break below $50,000 would signal a significant shift in market sentiment.</p><h2>Resistance Zones</h2><p>Immediate resistance sits at the $70,000-$72,000 range, where previous selling pressure has intensified. A decisive break above $75,000 with strong volume would likely trigger a push toward the all-time high zone above $100,000. On-chain data suggests significant supply walls exist at these levels.</p><h2>Outlook</h2><p>The confluence of institutional accumulation, reduced miner selling post-halving, and growing ETF inflows suggests a constructive medium-term outlook. However, macroeconomic headwinds including elevated oil prices and geopolitical tensions add uncertainty to short-term price action.</p>' ),

            array( 'title' => 'Stablecoins Explained: USDT, USDC, and DAI Compared', 'category' => 'Education', 'tags' => array('Stablecoins','USDT','USDC'), 'content' => '<h2>What Are Stablecoins?</h2><p>Stablecoins are cryptocurrencies designed to maintain a stable value, typically pegged 1:1 to the US Dollar. They serve as a bridge between traditional finance and the crypto ecosystem, providing a safe haven during market volatility and enabling fast, low-cost global transfers.</p><h2>Types of Stablecoins</h2><p>Fiat-collateralized stablecoins like USDT (Tether) and USDC (Circle) are backed by reserves of US dollars and Treasury bills. Crypto-collateralized stablecoins like DAI (MakerDAO) are backed by over-collateralized crypto assets locked in smart contracts. Algorithmic stablecoins use mathematical formulas to maintain their peg.</p><h2>Key Differences</h2><p>USDT dominates with the highest market cap and trading volume, making it the most liquid option. USDC is favored for its regulatory compliance and transparent monthly attestations by Deloitte. DAI offers true decentralization — no single entity controls it, and it can\'t be frozen or censored. Each serves different use cases depending on your priorities.</p>' ),

            array( 'title' => 'Weekly Crypto Roundup: Top Movers, Regulatory Updates, and DeFi Highlights', 'category' => 'Crypto News', 'tags' => array('Crypto','Weekly','Roundup'), 'content' => '<h2>Market Overview</h2><p>The crypto market showed mixed signals this week as Bitcoin traded sideways while altcoins experienced higher volatility. Total crypto market capitalization fluctuated around the $2.5 trillion level, with Bitcoin dominance holding above 55%, suggesting continued preference for large-cap assets during uncertain times.</p><h2>Top Gainers</h2><p>Several mid-cap altcoins posted notable gains this week, led by projects with strong fundamental catalysts. Layer-2 scaling solutions continued to attract attention as Ethereum gas fees rose, driving users toward more cost-effective alternatives. The AI-crypto narrative also saw renewed interest with several projects announcing partnerships.</p><h2>Regulatory Developments</h2><p>Regulatory clarity continued to improve across major markets. The ongoing evolution of crypto-specific legislation in the US, EU, and Asia signals growing mainstream acceptance. Key developments this week included new guidance on stablecoin reserves and updated frameworks for crypto asset classification.</p><h2>Looking Ahead</h2><p>Next week, markets will focus on central bank meeting minutes, upcoming token unlocks, and key technical levels. The Fear & Greed Index suggests cautious sentiment, which historically has preceded periods of accumulation by long-term holders.</p>' ),

            array( 'title' => 'The Complete Guide to Crypto Staking: Earn Passive Income', 'category' => 'Education', 'tags' => array('Staking','Passive Income','Guide'), 'content' => '<h2>What Is Staking?</h2><p>Staking is the process of locking up cryptocurrency to help validate transactions on a Proof of Stake (PoS) blockchain network. In return for securing the network, stakers earn rewards — typically 4-15% annually depending on the network. It\'s one of the most popular ways to earn passive income in crypto.</p><h2>Popular Staking Options</h2><p>Ethereum staking offers approximately 3-5% APY and is the largest staking ecosystem. Solana staking yields around 5-7% through validators. Cardano offers 3-5% with one of the simplest delegation processes. Many exchanges like Coinbase and Binance offer simplified staking services, though self-custody staking provides higher rewards and more control.</p><h2>Liquid Staking</h2><p>Liquid staking protocols like Lido (stETH) and Rocket Pool (rETH) let you stake while maintaining liquidity. You receive a derivative token representing your staked position, which can be used in DeFi protocols to earn additional yield. This innovation has become one of the fastest-growing sectors in crypto.</p><h2>Risks to Consider</h2><p>Staking involves lock-up periods (unstaking can take days to weeks), slashing risk (penalties for validator misbehavior), smart contract risk (for liquid staking), and the underlying price volatility of the staked asset. Always diversify your staking across multiple protocols and networks.</p>' ),

            array( 'title' => 'NFTs in 2026: Beyond Art — Real Utility and Use Cases', 'category' => 'DeFi & Web3', 'tags' => array('NFT','Web3','Innovation'), 'content' => '<h2>The Evolution of NFTs</h2><p>Non-fungible tokens (NFTs) have evolved far beyond profile picture collections and digital art. In 2026, NFTs serve as the backbone for digital identity, real-world asset tokenization, gaming economies, and decentralized credentials. The technology has matured from speculative hype to practical utility across multiple industries.</p><h2>Real-World Asset Tokenization</h2><p>One of the most significant developments is the tokenization of real-world assets (RWAs) using NFT technology. Real estate, luxury goods, intellectual property, and even carbon credits are being represented as NFTs, enabling fractional ownership, instant transfer, and global liquidity for traditionally illiquid assets.</p><h2>Gaming and the Metaverse</h2><p>Play-to-earn gaming has matured into "play-and-own" models where NFTs represent in-game items, characters, and land. Players genuinely own their digital assets and can trade them freely across marketplaces. Major game studios have begun integrating blockchain technology, bringing NFTs to mainstream gaming audiences.</p>' ),

            array( 'title' => 'Altcoin Season: What It Is and How to Prepare', 'category' => 'Altcoins', 'tags' => array('Altcoins','Trading','Strategy'), 'content' => '<h2>What Is Altcoin Season?</h2><p>Altcoin season refers to a market phase where alternative cryptocurrencies (altcoins) outperform Bitcoin. It\'s measured by the Altcoin Season Index — when 75% or more of the top 50 altcoins outperform Bitcoin over a 90-day period, the market is considered to be in altcoin season.</p><h2>Identifying the Cycle</h2><p>Crypto markets typically follow a pattern: Bitcoin leads the rally, attracting capital from traditional markets. As Bitcoin consolidates, profits rotate into large-cap altcoins (ETH, SOL, BNB), then into mid-caps, and finally into small-caps and meme coins. This rotation creates waves of opportunity across different market segments.</p><h2>How to Prepare</h2><p>Build watchlists across different categories: Layer-1 platforms, DeFi protocols, AI tokens, gaming, and infrastructure. Set price alerts at key support levels. Develop a position sizing strategy — never go all-in on a single altcoin. Take profits progressively as prices rise and always keep some capital in stablecoins for new opportunities.</p>' ),

            array( 'title' => 'Crypto Tax Guide: What You Need to Know', 'category' => 'Education', 'tags' => array('Tax','Regulation','Guide'), 'content' => '<h2>Crypto Taxation Basics</h2><p>In most countries, cryptocurrencies are treated as property for tax purposes. This means every trade, sale, or conversion is a taxable event that must be reported. Even swapping one crypto for another (like BTC to ETH) triggers a capital gains calculation in many jurisdictions.</p><h2>Taxable Events</h2><p>Common taxable events include: selling crypto for fiat currency, trading one cryptocurrency for another, using crypto to purchase goods or services, receiving crypto as payment or income, and earning staking or mining rewards. Simply holding crypto without selling is generally not taxable.</p><h2>Record Keeping</h2><p>Maintain detailed records of all transactions including dates, amounts, fair market value at time of transaction, and fees paid. Tools like CoinTracker, Koinly, and TokenTax can automatically import transactions from exchanges and wallets to generate tax reports. Many jurisdictions now require crypto-specific tax reporting forms.</p>' ),

            array( 'title' => 'EUR/USD Analysis: Central Bank Divergence Drives Forex Markets', 'category' => 'Forex News', 'tags' => array('Forex','EUR/USD','Analysis'), 'content' => '<h2>ECB vs Fed Policy Divergence</h2><p>The European Central Bank and the Federal Reserve continue on divergent monetary policy paths, creating significant opportunities and risks in the EUR/USD pair. While the ECB has signaled a more dovish stance amid weakening Eurozone growth, the Fed maintains a data-dependent approach with elevated inflation concerns keeping rate cut expectations in check.</p><h2>Technical Outlook</h2><p>EUR/USD is trading near the 1.0850 level, with key support at 1.0750 and resistance at 1.0950. The pair has been range-bound for several weeks, suggesting a breakout is imminent. RSI readings near 50 confirm the neutral stance, while declining volatility often precedes significant moves.</p><h2>Macro Factors</h2><p>Key drivers to watch include Eurozone PMI data, US non-farm payrolls, and energy prices — particularly crude oil, which impacts inflation expectations on both sides of the Atlantic. The ongoing geopolitical situation continues to create safe-haven demand for the US dollar, adding downward pressure on EUR/USD.</p>' ),
        );

        // Backdate articles across 30 days
        $total = count( $seed_articles );
        for ( $i = 0; $i < $total; $i++ ) {
            $article = $seed_articles[ $i ];
            $days_ago = $total - $i; // Oldest first
            $post_date = date( 'Y-m-d H:i:s', strtotime( "-{$days_ago} days 08:00:00" ) );

            // Check if already exists
            $exists = get_posts( array( 'title' => $article['title'], 'post_type' => 'post', 'numberposts' => 1 ) );
            if ( ! empty( $exists ) ) continue;

            // Add disclaimer + internal links
            $content = $article['content'];
            $content .= '<p>📊 <a href="' . $home_url . 'crypto-markets/">Live Crypto Prices</a> · 📈 <a href="' . $home_url . 'forex-charts/">Forex Charts</a> · 📡 <a href="' . $home_url . 'trading-signals/">Trading Signals</a></p>';

            $cat_id = get_cat_ID( $article['category'] );
            if ( ! $cat_id ) { wp_insert_term( $article['category'], 'category' ); $cat_id = get_cat_ID( $article['category'] ); }

            $post_id = wp_insert_post( array(
                'post_title'    => $article['title'],
                'post_content'  => $content,
                'post_status'   => 'publish', // seed articles always publish immediately
                'post_type'     => 'post',
                'post_author'   => 1,
                'post_date'     => $post_date,
                'post_date_gmt' => get_gmt_from_date( $post_date ),
                'post_category' => $cat_id ? array( $cat_id ) : array(),
            ) );

            if ( $post_id && ! is_wp_error( $post_id ) ) {
                wp_set_post_tags( $post_id, $article['tags'] );
                $desc = wp_trim_words( strip_tags( $article['content'] ), 25 );
                update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
            }
        }
    }

    public static function generate_daily_post() {
        // v54: If admin has pinned a fixed topic in the Autopilot tab, use it.
        $pref = get_option( 'bt_ai_autopilot_topic', '' );
        if ( ! empty( $pref ) && isset( self::$topics[ $pref ] ) && $pref !== 'ai-daily-report' ) {
            self::generate_daily_post_forced( $pref );
            return;
        }
        // Otherwise rotate — but exclude the on-demand 'ai-daily-report' from the rotation.
        $topic_keys = array_values( array_filter( array_keys( self::$topics ), function( $k ) {
            return $k !== 'ai-daily-report';
        } ) );
        $day_index  = date( 'N' ) - 1; // 0-6
        $topic_key  = $topic_keys[ $day_index % count( $topic_keys ) ];
        self::generate_daily_post_forced( $topic_key );
    }

    /**
     * Generate a post for a specific topic key (used by admin blog generator).
     * When $topic_key is empty, picks today's scheduled topic.
     */
    public static function generate_daily_post_forced( $topic_key = '' ) {
        if ( empty( $topic_key ) || ! isset( self::$topics[ $topic_key ] ) ) {
            // Fall back to today's scheduled topic
            $topic_keys = array_keys( self::$topics );
            $day_index  = date( 'N' ) - 1;
            $topic_key  = $topic_keys[ $day_index % count( $topic_keys ) ];
        }
        $topic    = self::$topics[ $topic_key ];
        $date_str = date( 'F j, Y' );
        $title    = sprintf( $topic['title'], $date_str );

        // Allow duplicate titles when manually triggered (admin intent)
        // Try AI Power plugin first
        if ( class_exists( 'WPAICG_PostGenerator' ) ) {
            do_action( 'wpaicg_generate_post', array(
                'topic'    => $topic['prompt'] . ' Date: ' . $date_str,
                'title'    => $title,
                'category' => $topic['category'],
                'length'   => 800,
            ) );
            return;
        }

        // v57: Try configured AI provider (Claude or OpenAI)
        $provider  = get_option( 'bt_ai_provider', 'claude' );
        $api_key   = $provider === 'openai' ? get_option( 'bt_openai_key', '' ) : get_option( 'bt_claude_key', '' );
        if ( ! empty( $api_key ) ) {
            $enriched_prompt = self::build_enriched_prompt( $topic['prompt'], $date_str );
            // v54: validated generation with 1 retry to prevent empty posts
            $content = self::generate_validated_content( $api_key, $enriched_prompt );
            if ( $content ) {
                self::create_post( $title, $content, $topic );
                return;
            }
            // If validation failed twice, log it for the admin
            error_log( 'BlockTicker AI: ' . $provider . ' generation failed validation for topic "' . $topic_key . '"; falling back to aggregated post.' );
        }

        // Fallback: Aggregated roundup from live RSS data
        self::create_aggregated_post( $title, $topic );
    }

    /**
     * v54: Generate content with a post-validation check and one retry.
     * Rejects empty output, stub output, or output under a minimum word count —
     * these are what caused the empty-body bug on the live site.
     */
    private static function generate_validated_content( $api_key, $prompt ) {
        $min_words = 300;
        for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
            $content = self::call_ai( $prompt );
            if ( empty( $content ) ) continue;
            $text_only = trim( wp_strip_all_tags( $content ) );
            if ( empty( $text_only ) ) continue;
            $word_count = str_word_count( $text_only );
            if ( $word_count < $min_words ) continue;
            // Reject outputs that are clearly stubs / error-style single lines
            if ( stripos( $text_only, "I cannot" ) === 0 || stripos( $text_only, "I'm unable" ) === 0 ) continue;
            return $content;
        }
        return null;
    }

    /**
     * FIX AI-01: Build an enriched prompt with live market data injected.
     * This grounds the AI in real current prices rather than training data.
     */
    private static function build_enriched_prompt( $base_prompt, $date_str ) {
        $crypto = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        $forex  = BT_Widgets::get_json_option( 'fxlm_forex_data' );
        $fng    = BT_Widgets::get_json_option( 'fxlm_fear_greed_data' );
        $news   = BT_Widgets::get_json_option( 'fxlm_news_items' );

        $data_context = "\n\n--- LIVE MARKET DATA (as of " . $date_str . ", use these exact figures in your article) ---\n";

        // Top 15 coins + compute top movers
        $top_gainer = null; $top_loser = null;
        if ( ! empty( $crypto['coins'] ) ) {
            $data_context .= "CRYPTO PRICES (top 15 by market cap):\n";
            $coins_top = array_slice( $crypto['coins'], 0, 15 );
            foreach ( $coins_top as $coin ) {
                $chg  = floatval( $coin['price_change_percentage_24h'] ?? 0 );
                $chg7 = floatval( $coin['price_change_percentage_7d_in_currency'] ?? $coin['price_change_percentage_7d'] ?? 0 );
                $mcap = floatval( $coin['market_cap'] ?? 0 );
                $vol  = floatval( $coin['total_volume'] ?? 0 );
                $data_context .= sprintf(
                    "- %s (%s): $%s | 24h %s%s%% | 7d %s%s%% | MCap $%s | Vol24h $%s\n",
                    strtoupper( $coin['symbol'] ),
                    $coin['name'],
                    number_format( floatval( $coin['current_price'] ), $coin['current_price'] >= 1 ? 2 : 6 ),
                    $chg  >= 0 ? '+' : '', number_format( $chg, 2 ),
                    $chg7 >= 0 ? '+' : '', number_format( $chg7, 2 ),
                    self::fmt_big( $mcap ), self::fmt_big( $vol )
                );
                if ( ! $top_gainer || $chg > floatval( $top_gainer['price_change_percentage_24h'] ?? 0 ) ) $top_gainer = $coin;
                if ( ! $top_loser  || $chg < floatval( $top_loser['price_change_percentage_24h']  ?? 0 ) ) $top_loser  = $coin;
            }
            if ( $top_gainer && $top_loser ) {
                $data_context .= sprintf(
                    "TOP 24H MOVERS: %s +%s%% leads gainers; %s %s%% leads losers.\n",
                    strtoupper( $top_gainer['symbol'] ),
                    number_format( floatval( $top_gainer['price_change_percentage_24h'] ?? 0 ), 2 ),
                    strtoupper( $top_loser['symbol'] ),
                    number_format( floatval( $top_loser['price_change_percentage_24h'] ?? 0 ), 2 )
                );
            }
        }

        // Global crypto market metrics
        if ( ! empty( $crypto['global'] ) ) {
            $g = $crypto['global'];
            $data_context .= "GLOBAL CRYPTO: Total Market Cap $" . self::fmt_big( floatval( $g['total_market_cap_usd'] ?? 0 ) );
            if ( isset( $g['market_cap_change_percentage_24h_usd'] ) ) {
                $mc_chg = floatval( $g['market_cap_change_percentage_24h_usd'] );
                $data_context .= ' (' . ( $mc_chg >= 0 ? '+' : '' ) . number_format( $mc_chg, 2 ) . '% 24h)';
            }
            if ( isset( $g['btc_dominance'] ) ) $data_context .= ' | BTC dominance ' . number_format( floatval( $g['btc_dominance'] ), 2 ) . '%';
            $data_context .= "\n";
        }

        // Forex rates (majors + exotic)
        if ( ! empty( $forex['rates'] ) ) {
            $data_context .= "FOREX RATES:\n";
            foreach ( $forex['rates'] as $pair => $data ) {
                $chg = floatval( $data['change'] ?? 0 );
                $data_context .= "- " . $pair . ": " . number_format( floatval( $data['rate'] ), 4 )
                    . " (" . ( $chg >= 0 ? '+' : '' ) . number_format( $chg, 3 ) . "% 24h)\n";
            }
        }

        // Fear & Greed
        if ( ! empty( $fng['data'][0] ) ) {
            $fg_val = intval( $fng['data'][0]['value'] );
            $fg_cls = $fng['data'][0]['value_classification'] ?? '';
            $data_context .= "FEAR & GREED INDEX: {$fg_val}/100 ({$fg_cls})";
            // Add contrarian context
            if ( $fg_val < 25 )      $data_context .= " -- historically a contrarian accumulation zone";
            elseif ( $fg_val > 75 )  $data_context .= " -- historically precedes pullbacks";
            $data_context .= "\n";
        }

        // Top news headlines (last 10) -- lets AI tie price moves to specific catalysts
        if ( ! empty( $news ) && is_array( $news ) ) {
            $data_context .= "RECENT NEWS HEADLINES (last 24h, most relevant first):\n";
            $n = 0;
            foreach ( array_slice( $news, 0, 10 ) as $item ) {
                if ( empty( $item['title'] ) ) continue;
                $src = ! empty( $item['source'] ) ? ' [' . $item['source'] . ']' : '';
                $data_context .= "- " . $item['title'] . $src . "\n";
                $n++;
                if ( $n >= 10 ) break;
            }
        }

        $data_context .= "--- END LIVE DATA ---\n\n";
        $data_context .= "CRITICAL WRITING REQUIREMENTS:\n";
        $data_context .= "1. Reference the EXACT prices, percentages, and numbers above. Never invent figures.\n";
        $data_context .= "2. If a notable move occurred, cite a specific news headline above as the catalyst (or note 'no clear catalyst emerged').\n";
        $data_context .= "3. Open with a concrete market observation (a number, a ratio, a divergence) -- not a generic intro.\n";
        $data_context .= "4. Structure: (a) Lead with today's most significant move, (b) Explain why, (c) Put it in 7-day context using the 7d % above, (d) Name specific levels or thresholds to watch, (e) Close with 2-3 actionable takeaways for retail traders.\n";
        $data_context .= "5. Every H2 section must advance the analysis -- no filler headers like 'Conclusion' or 'Final Thoughts'.\n";
        $data_context .= "6. Include at least one cross-market correlation (e.g. how forex or F&G relates to crypto moves).\n";
        $data_context .= "7. Tag levels as specific numbers (e.g. '$74,200 support') not vague ranges.\n";
        $data_context .= "8. Byline voice: Authoritative senior analyst. Confident, clear, zero hedge phrases like 'could potentially'.\n";

        return $base_prompt . $data_context . ' Date: ' . $date_str;
    }

    /** Compact number formatter for prompt context. */
    /**
     * Format a large number with SI suffix (K/M/B/T).
     *
     * @deprecated 69.0.0 Use BT_Utils::fmt_large() directly. This thin wrapper
     *             exists for backward compatibility with earlier call sites.
     * @param float $n
     * @return string
     */
    private static function fmt_big( $n ) {
        return BT_Utils::fmt_large( $n, 2, 2 );
    }


    /**
     * Call Claude API (claude-sonnet-4-5) to generate article content.
     * Uses the Anthropic Messages API with live market data in the prompt.
     */
    /**
     * v57: AI provider dispatcher.
     * Reads the fxlm_ai_provider option ('claude' | 'openai') and routes to the
     * appropriate call_*() method. Returns the generated text, or false on error.
     *
     * The system prompt and user prompt are identical across providers — only the
     * HTTP shape + auth differ. This keeps all topic prompts provider-agnostic.
     */
    private static function call_ai( $prompt ) {
        $provider = get_option( 'bt_ai_provider', 'claude' );
        if ( $provider === 'openai' ) {
            $key = get_option( 'bt_openai_key', '' );
            if ( empty( $key ) ) return false;
            return self::call_openai( $key, $prompt );
        }
        // Default: Claude
        $key = get_option( 'bt_claude_key', '' );
        if ( empty( $key ) ) return false;
        return self::call_claude( $key, $prompt );
    }

    /** Shared system prompt used by both providers. */
    private static function ai_system_prompt() {
        return 'You are a senior crypto and forex analyst at BlockTicker. Write in an authoritative, analytical tone that adds GENUINE insight beyond raw data. For every article: (1) Provide a clear BlockTicker perspective — what this means for retail investors specifically, (2) Include 2-3 actionable takeaways in a dedicated section, (3) Reference specific price levels, percentages, or data points from the market figures provided, (4) Compare current conditions to historical context where relevant. Structure with H2 subheadings. Avoid generic filler sentences. Every paragraph must add unique analytical value. Never include legal disclaimers in the body — these are added separately.';
    }

    private static function call_claude( $key, $prompt ) {
        $model = get_option( 'bt_claude_model', 'claude-sonnet-4-5' );
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'timeout' => 90,
            'headers' => array(
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'      => $model,
                'max_tokens' => 2000,
                'system'     => self::ai_system_prompt(),
                'messages'   => array(
                    array( 'role' => 'user', 'content' => $prompt ),
                ),
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'BlockTicker Claude API error: ' . $response->get_error_message() );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['error'] ) ) {
            error_log( 'BlockTicker Claude API error: ' . wp_json_encode( $body['error'] ) );
            return false;
        }

        return $body['content'][0]['text'] ?? false;
    }

    /**
     * v57: OpenAI / ChatGPT Chat Completions.
     * Compatible with gpt-4o, gpt-4o-mini, gpt-4-turbo.
     * Uses system + user message roles, extracts choices[0].message.content.
     */
    private static function call_openai( $key, $prompt ) {
        $model = get_option( 'bt_openai_model', 'gpt-4o' );
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'timeout' => 90,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'       => $model,
                'max_tokens'  => 2000,
                'temperature' => 0.7,
                'messages'    => array(
                    array( 'role' => 'system', 'content' => self::ai_system_prompt() ),
                    array( 'role' => 'user',   'content' => $prompt ),
                ),
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'BlockTicker OpenAI API error: ' . $response->get_error_message() );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['error'] ) ) {
            error_log( 'BlockTicker OpenAI API error: ' . wp_json_encode( $body['error'] ) );
            return false;
        }

        return $body['choices'][0]['message']['content'] ?? false;
    }

    /**
     * Convert markdown-style headings/bold to HTML, then wrap in rich styled layout.
     */
    private static function markdown_to_html( $text ) {
        // Convert markdown headings
        $text = preg_replace( '/^### (.+)$/m', '<h3>$1</h3>', $text );
        $text = preg_replace( '/^## (.+)$/m',  '<h2>$1</h2>', $text );
        $text = preg_replace( '/^# (.+)$/m',   '<h1>$1</h1>', $text );
        // Bold/italic
        $text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
        $text = preg_replace( '/\*(.+?)\*/',     '<em>$1</em>', $text );
        // Bullet lists
        $text = preg_replace( '/^[*-] (.+)$/m', '<li>$1</li>', $text );
        $text = preg_replace( '/(<li>.*<\/li>
?)+/s', '<ul>$0</ul>', $text );
        // Remove duplicate tags from wpautop
        $text = wpautop( $text );
        // Clean up malformed p tags around block elements
        $text = preg_replace( '/<p>\s*(<h[1-6]>)/', '$1', $text );
        $text = preg_replace( '/(<\/h[1-6]>)\s*<\/p>/', '$1', $text );
        return $text;
    }

    private static function create_post( $title, $content, $topic ) {
        // Convert markdown to HTML
        $html_body = self::markdown_to_html( $content );

        // Build rich article layout
        $site = get_option('bt_site_name','BlockTicker');
        $home = home_url('/');
        $tag_links = '';
        foreach ($topic['tags'] as $tag) {
            $tag_links .= '<a href="'.esc_url($home.'?tag='.sanitize_title($tag)).'" class="fxlm-post-tag">'.esc_html($tag).'</a> ';
        }

        // Internal link widgets matching topic
        $related_links = array(
            'bitcoin-analysis'  => array(
                array('📊 Live BTC Price',      $home.'crypto/bitcoin/'),
                array('📈 BTC/USD Chart',        $home.'crypto/bitcoin/'),
                array('🎯 Fear & Greed Index',   $home.'tools/#fear-greed'),
                array('📡 Trading Signals',      $home.'trading-signals/'),
            ),
            'ethereum-update'   => array(
                array('📊 Live ETH Price',       $home.'crypto/ethereum/'),
                array('🔗 DeFi Tokens',          $home.'crypto-category/defi/'),
                array('📈 Crypto Markets',       $home.'crypto-markets/'),
                array('🎯 Fear & Greed Index',   $home.'tools/#fear-greed'),
            ),
            'forex-weekly'      => array(
                array('💱 EUR/USD Chart',        $home.'forex/eur-usd/'),
                array('📅 Economic Calendar',    $home.'economic-calendar/'),
                array('📡 Forex Signals',        $home.'trading-signals/'),
                array('📈 All Forex Charts',     $home.'forex-charts/'),
            ),
            'default'           => array(
                array('📊 Crypto Prices',        $home.'crypto-markets/'),
                array('💱 Forex Charts',         $home.'forex-charts/'),
                array('📡 Trading Signals',      $home.'trading-signals/'),
                array('🛠️ Crypto Tools',         $home.'tools/'),
                array('🚀 Gainers & Losers',     $home.'gainers-losers/'),
                array('⭐ My Watchlist',         $home.'watchlist/'),
            ),
        );
        $topic_key = $topic['tags'][0] ? sanitize_title($topic['tags'][0]) : 'default';
        // Find matching key
        $link_set = $related_links['default'];
        foreach(array_keys($related_links) as $k){
            if(strpos(strtolower($title), str_replace('-',' ',$k)) !== false){ $link_set=$related_links[$k]; break; }
        }
        $link_html = '';
        foreach($link_set as $lnk){
            $link_html .= '<a href="'.esc_url($lnk[1]).'" class="fxlm-post-related-btn">'.esc_html($lnk[0]).'</a>';
        }

        // Fetch live price widget for the relevant asset
        $crypto = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        $price_widget = '';
        $cat_lower = strtolower($topic['category']);
        if ( !empty($crypto['coins']) && (strpos($cat_lower,'crypto')!==false || strpos($cat_lower,'bitcoin')!==false || strpos($cat_lower,'ethereum')!==false) ) {
            $top3 = array_slice($crypto['coins'], 0, 3);
            $price_widget = '<div class="fxlm-post-prices">';
            foreach($top3 as $c){
                $chg = floatval($c['price_change_percentage_24h']??0);
                $cls = $chg>=0?'up':'down'; $arr=$chg>=0?'▲':'▼';
                $price_widget .= '<div class="fxlm-post-price-item">';
                $price_widget .= '<img src="'.esc_url($c['image']).'" width="22" height="22" loading="lazy" alt="">';
                $price_widget .= '<div><strong>'.esc_html(strtoupper($c['symbol'])).'</strong>';
                $price_widget .= '<span>$'.number_format(floatval($c['current_price']),2).'</span>';
                $price_widget .= '<span class="'.$cls.'">'.$arr.' '.number_format(abs($chg),2).'%</span></div>';
                $price_widget .= '</div>';
            }
            $price_widget .= '<a href="'.esc_url($home.'crypto-markets/').'">View all →</a></div>';
        }

        $html = '
<div class="fxlm-post-body">

    ' . ( $price_widget ? '<div class="fxlm-post-live-prices">'.$price_widget.'</div>' : '' ) . '

    <div class="fxlm-post-tags">' . $tag_links . '</div>

    <div class="fxlm-post-content">' . $html_body . '</div>

    <div class="fxlm-post-related">
        <h3 class="fxlm-post-related-title">📊 Explore on BlockTicker</h3>
        <div class="fxlm-post-related-links">' . $link_html . '</div>
    </div>

</div>';


        $cat_id = get_cat_ID( $topic['category'] );
        if ( ! $cat_id ) {
            wp_insert_term( $topic['category'], 'category' );
            $cat_id = get_cat_ID( $topic['category'] );
        }

        $post_id = wp_insert_post( array(
            'post_title'    => $title,
            'post_content'  => $html,
            'post_status'   => get_option( 'bt_ai_review_mode', '0' ) === '1' ? 'pending' : 'publish',
            'post_type'     => 'post',
            'post_author'   => 1,
            'post_category' => $cat_id ? array( $cat_id ) : array(),
        ) );

        if ( $post_id && ! is_wp_error( $post_id ) ) {
            // Add tags
            wp_set_post_tags( $post_id, $topic['tags'] );

            // Set Yoast SEO meta
            $desc = wp_trim_words( strip_tags( $content ), 25 );
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
            update_post_meta( $post_id, '_yoast_wpseo_focuskw', $topic['tags'][0] ?? '' ); // FIX SEO-01: single focus keyword

            // Try to set featured image from news items
            self::set_featured_image( $post_id );

            // v55: If this is the daily institutional report, also generate Twitter thread + newsletter
            $topic_key = '';
            foreach ( self::$topics as $tk => $td ) {
                if ( $td['title'] === $topic['title'] ) { $topic_key = $tk; break; }
            }
            if ( $topic_key === 'ai-daily-report' && get_option( 'bt_ai_multiformat', '1' ) === '1' ) {
                self::generate_multiformat_extras( $post_id, $content );
            }
        }

        return $post_id;
    }

    /**
     * v55: Generate a Twitter/X thread and a newsletter version alongside a blog post,
     * and attach them as post meta (_bt_twitter_thread, _bt_newsletter_body).
     * Called only for the ai-daily-report topic. Uses the same live data context
     * as the blog post to keep figures consistent across all three outputs.
     */
    private static function generate_multiformat_extras( $post_id, $blog_content ) {
        // v57: Check whichever provider is configured
        $provider = get_option( 'bt_ai_provider', 'claude' );
        $api_key  = $provider === 'openai' ? get_option( 'bt_openai_key', '' ) : get_option( 'bt_claude_key', '' );
        if ( empty( $api_key ) ) return;

        $handle = get_option( 'bt_twitter_handle', '@blocktickerIO' );

        // Share the same data context the blog post used (cached for consistency)
        $date_str    = date( 'F j, Y' );
        $data_ctx    = self::build_enriched_prompt( '', $date_str );

        // 1. Twitter thread
        $thread_prompt = "You are writing a Twitter/X thread for {$handle}, an institutional crypto research account. "
            . "Using ONLY the live market data below, produce a thread of 8-12 tweets about today's market. "
            . "Requirements:\n"
            . "- First tweet is the HOOK: a high-impact macro insight or tension statement with the boldest number.\n"
            . "- Each tweet max 260 characters.\n"
            . "- Punchy, data-driven, institutional vocabulary (liquidity, regime, positioning, structure).\n"
            . "- Cover: market regime, BTC breakdown, ETH breakdown, liquidity/whale interpretation, short-term outlook, closing synthesis.\n"
            . "- No hashtags unless absolutely needed. Max 1 emoji per tweet.\n"
            . "- No retail slang (no 'moon', 'pump', 'wagmi', 'to the moon').\n"
            . "- Final tweet synthesizes and closes.\n"
            . "OUTPUT FORMAT: Return ONLY the tweets, each on its own line, prefixed with 'Tweet 1:', 'Tweet 2:', etc. No preamble, no commentary."
            . $data_ctx;
        $thread_raw = self::call_ai( $thread_prompt );
        $thread     = self::parse_twitter_thread( $thread_raw );
        if ( ! empty( $thread ) ) {
            update_post_meta( $post_id, '_bt_twitter_thread', $thread );
            // v60: auto-publish first 3 tweets to X if enabled
            self::maybe_auto_publish_twitter( $post_id );
        }

        // 2. Newsletter
        $nl_prompt = "You are writing an institutional daily market briefing newsletter for BlockTicker subscribers. "
            . "Using ONLY the data below, produce a concise briefing.\n"
            . "STRUCTURE (use these exact section labels):\n"
            . "SUBJECT_LINE: [one institutional subject line, max 60 chars, no clickbait]\n"
            . "EXECUTIVE_SUMMARY: [3-5 bullet points, each one sentence, highest-leverage insights]\n"
            . "MARKET_SNAPSHOT: [one paragraph interpreting BTC and ETH key stats together]\n"
            . "KEY_LEVELS: [support/resistance for BTC and ETH as bullet points with exact numbers]\n"
            . "WHALE_AND_FLOW: [one short paragraph on liquidity and volume dynamics]\n"
            . "OUTLOOK: [one paragraph, 24-72 hour base case with invalidation level]\n"
            . "KEY_RISKS: [3 bullet points, each tied to a specific data point or headline]\n"
            . "STYLE: Clear, fast to read, institutional tone for traders and investors. No retail slang. No hedge words. No disclaimers."
            . $data_ctx;
        $newsletter = self::call_ai( $nl_prompt );
        if ( ! empty( $newsletter ) ) {
            update_post_meta( $post_id, '_bt_newsletter_body', $newsletter );
        }
    }

    /**
     * Parse Claude's thread output into an ordered array of tweets.
     * Accepts formats like "Tweet 1: ...", "1. ...", or bare newlines.
     */
    private static function parse_twitter_thread( $raw ) {
        if ( empty( $raw ) ) return array();
        $text   = wp_strip_all_tags( $raw );
        $lines  = preg_split( '/\r\n|\r|\n/', $text );
        $tweets = array();
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) continue;
            // Match "Tweet N:" or "N." or "N/" prefix and strip it
            $clean = preg_replace( '/^(tweet\s*\d+[:\.\)]|[0-9]+[\.\)\/])\s*/i', '', $line );
            $clean = trim( $clean );
            if ( mb_strlen( $clean ) < 10 ) continue; // skip stub lines
            // Hard cap at 275 chars (Twitter limit is 280; leave room for link if added)
            if ( mb_strlen( $clean ) > 275 ) {
                $clean = mb_substr( $clean, 0, 272 ) . '…';
            }
            $tweets[] = $clean;
            if ( count( $tweets ) >= 12 ) break;
        }
        return $tweets;
    }

    /**
     * v60: Auto-publish the first N tweets of a thread to X (Twitter) via API v2.
     * Uses OAuth 1.0a user-context auth (required by POST /2/tweets).
     * Returns an array: [success => bool, ids => [], error => string|null, count => int]
     */
    public static function publish_first_tweets_to_twitter( $post_id, $n = 3 ) {
        $ck = get_option( 'bt_twitter_api_key', '' );
        $cs = get_option( 'bt_twitter_api_secret', '' );
        $tk = get_option( 'bt_twitter_access_token', '' );
        $ts = get_option( 'bt_twitter_access_token_secret', '' );

        if ( empty( $ck ) || empty( $cs ) || empty( $tk ) || empty( $ts ) ) {
            return array(
                'success' => false,
                'error'   => 'Twitter API credentials missing. Add them in BlockTicker → AI Blog Generator → AI Analysis tab.',
                'ids'     => array(),
                'count'   => 0,
            );
        }

        $thread = get_post_meta( $post_id, '_bt_twitter_thread', true );
        if ( empty( $thread ) || ! is_array( $thread ) ) {
            return array(
                'success' => false,
                'error'   => 'No Twitter thread found for this post. Re-generate the report with multi-format enabled.',
                'ids'     => array(),
                'count'   => 0,
            );
        }

        $n         = max( 1, min( (int) $n, count( $thread ) ) );
        $published = array();
        $reply_to  = null;

        for ( $i = 0; $i < $n; $i++ ) {
            $text = trim( $thread[ $i ] );
            // Hard safety cap at 280 (Twitter API rejects longer)
            if ( mb_strlen( $text ) > 280 ) {
                $text = mb_substr( $text, 0, 277 ) . '…';
            }
            if ( empty( $text ) ) continue;

            $body_arr = array( 'text' => $text );
            if ( $reply_to ) {
                $body_arr['reply'] = array( 'in_reply_to_tweet_id' => (string) $reply_to );
            }

            $url  = 'https://api.twitter.com/2/tweets';
            $auth = self::twitter_oauth1_header( $url, 'POST', array(), $ck, $cs, $tk, $ts );

            $response = wp_remote_post( $url, array(
                'headers' => array(
                    'Authorization' => $auth,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $body_arr ),
                'timeout' => 20,
            ) );

            if ( is_wp_error( $response ) ) {
                return array(
                    'success' => false,
                    'error'   => 'HTTP error: ' . $response->get_error_message(),
                    'ids'     => $published,
                    'count'   => count( $published ),
                );
            }

            $code   = wp_remote_retrieve_response_code( $response );
            $result = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( $code !== 201 || empty( $result['data']['id'] ) ) {
                // v119.27.0: route every 4xx through the central decoder so the
                // admin sees an actionable, deep-linked message instead of the
                // raw "HTTP 401" or "HTTP 403" that earlier versions surfaced.
                $err_msg = 'Tweet ' . ( $i + 1 ) . ' failed (HTTP ' . $code . ').';
                if ( class_exists( 'BT_Autopilot_Health' ) ) {
                    $decoded = BT_Autopilot_Health::decode_twitter_error( $code, $result );
                    if ( ! empty( $decoded['msg'] ) ) {
                        $err_msg = 'Tweet ' . ( $i + 1 ) . ' failed. ' . $decoded['msg'];
                    }
                } else {
                    // Fallback to raw body parse (legacy behaviour)
                    $api_msg = $result['errors'][0]['message']
                        ?? $result['detail']
                        ?? $result['title']
                        ?? 'Unknown Twitter API error';
                    $err_msg = 'Tweet ' . ( $i + 1 ) . ' failed (HTTP ' . $code . '): ' . $api_msg;
                }
                return array(
                    'success' => false,
                    'error'   => $err_msg,
                    'ids'     => $published,
                    'count'   => count( $published ),
                );
            }

            $reply_to    = $result['data']['id'];
            $published[] = $result['data']['id'];

            // Small delay between tweets to avoid rate-limit blowback
            if ( $i < $n - 1 ) {
                usleep( 500000 ); // 0.5s
            }
        }

        update_post_meta( $post_id, '_bt_twitter_published_ids', $published );
        update_post_meta( $post_id, '_bt_twitter_published_at', time() );

        return array(
            'success' => true,
            'error'   => null,
            'ids'     => $published,
            'count'   => count( $published ),
        );
    }

    /**
     * Build an OAuth 1.0a Authorization header for a Twitter v2 request.
     * JSON body is NOT included in the signature base string (Twitter v2 behavior).
     */
    private static function twitter_oauth1_header( $url, $method, $query_params, $ck, $cs, $tk, $ts ) {
        $oauth = array(
            'oauth_consumer_key'     => $ck,
            'oauth_nonce'            => bin2hex( random_bytes( 16 ) ),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string) time(),
            'oauth_token'            => $tk,
            'oauth_version'          => '1.0',
        );

        // Combine OAuth params with any query params for signing
        $signing_params = array_merge( $oauth, is_array( $query_params ) ? $query_params : array() );
        ksort( $signing_params );

        $pairs = array();
        foreach ( $signing_params as $k => $v ) {
            $pairs[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
        }
        $param_string = implode( '&', $pairs );

        $base_string = strtoupper( $method ) . '&'
            . rawurlencode( $url ) . '&'
            . rawurlencode( $param_string );

        $signing_key = rawurlencode( $cs ) . '&' . rawurlencode( $ts );

        $oauth['oauth_signature'] = base64_encode(
            hash_hmac( 'sha1', $base_string, $signing_key, true )
        );

        // Build Authorization header
        $header_parts = array();
        foreach ( $oauth as $k => $v ) {
            $header_parts[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
        }
        return 'OAuth ' . implode( ', ', $header_parts );
    }

    /**
     * v63: Queue the remaining tweets (4-12) of a thread to Typefully as a draft.
     * Uses Typefully API v1. Free tier supports draft creation via X-API-KEY header.
     * https://support.typefully.com/en/articles/8718287-typefully-api
     *
     * Why 4-12: tweets 1-3 are auto-published by publish_first_tweets_to_twitter().
     * Typefully handles the remaining hook-follow-ups as a queue-able draft, letting
     * the editor review + schedule via their UI rather than posting blindly.
     */
    public static function queue_remaining_tweets_to_typefully( $post_id ) {
        $api_key = get_option( 'bt_typefully_key', '' );
        if ( empty( $api_key ) ) {
            return array( 'success' => false, 'error' => 'Typefully API key missing. Add it in BlockTicker → AI Blog Generator → AI Analysis tab.' );
        }

        $thread = get_post_meta( $post_id, '_bt_twitter_thread', true );
        if ( empty( $thread ) || ! is_array( $thread ) || count( $thread ) < 4 ) {
            return array( 'success' => false, 'error' => 'No remaining tweets to queue (thread has fewer than 4 tweets).' );
        }

        // Skip the first 3 (auto-published separately) and queue the rest
        $remaining = array_slice( $thread, 3 );
        // Typefully separates tweets in a thread with exactly 4 newlines
        $content = implode( "\n\n\n\n", array_map( 'trim', $remaining ) );

        $response = wp_remote_post( 'https://api.typefully.com/v1/drafts/', array(
            'headers' => array(
                'X-API-KEY'    => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'content'    => $content,
                'threadify'  => false,  // We already have separators
                'share'      => true,   // Return shareable URL in response
            ) ),
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'error' => 'HTTP error: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 && $code !== 201 ) {
            $err = $body['detail'] ?? $body['message'] ?? ( 'HTTP ' . $code );
            return array( 'success' => false, 'error' => $err );
        }

        $draft_id  = $body['id']        ?? null;
        $share_url = $body['share_url'] ?? null;

        update_post_meta( $post_id, '_bt_typefully_draft_id', $draft_id );
        update_post_meta( $post_id, '_bt_typefully_share_url', $share_url );
        update_post_meta( $post_id, '_bt_typefully_queued_at', time() );

        return array(
            'success'   => true,
            'draft_id'  => $draft_id,
            'share_url' => $share_url,
            'count'     => count( $remaining ),
        );
    }

    /**
     * Called right after generate_multiformat_extras finishes. Auto-publishes
     * the first 3 tweets IF auto-publish is enabled.
     * v63: Also auto-queues tweets 4+ to Typefully if that integration is enabled.
     */
    public static function maybe_auto_publish_twitter( $post_id ) {
        if ( get_option( 'bt_twitter_autopublish', '0' ) !== '1' ) return;
        $result = self::publish_first_tweets_to_twitter( $post_id, 3 );
        if ( ! $result['success'] ) {
            // Log so the user can see why it didn't ship
            update_post_meta( $post_id, '_bt_twitter_autopublish_error', $result['error'] );
            return;
        }
        // v63: Auto-queue the remaining tweets to Typefully if enabled and key is set
        if ( get_option( 'bt_typefully_autoqueue', '0' ) === '1' && get_option( 'bt_typefully_key', '' ) ) {
            $tf = self::queue_remaining_tweets_to_typefully( $post_id );
            if ( ! $tf['success'] ) {
                update_post_meta( $post_id, '_bt_typefully_autoqueue_error', $tf['error'] );
            }
        }
    }

    private static function create_aggregated_post( $title, $topic ) {
        $news   = BT_Widgets::get_json_option( 'fxlm_news_items' );
        $crypto = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        $forex  = BT_Widgets::get_json_option( 'fxlm_forex_data' );
        $fng    = BT_Widgets::get_json_option( 'fxlm_fear_greed_data' );
        $date   = date( 'F j, Y' );
        $coins  = $crypto['coins'] ?? array();

        // ── Compute key metrics ───────────────────────────────────────────────
        $btc    = null; $eth = null; $total_mcap = 0; $breadth_up = 0; $breadth_n = 0;
        $top_gainer = null; $top_loser = null;
        foreach ( $coins as $c ) {
            $sym = strtoupper( $c['symbol'] ?? '' );
            if ( $sym === 'BTC' ) $btc = $c;
            if ( $sym === 'ETH' ) $eth = $c;
            $total_mcap += floatval( $c['market_cap'] ?? 0 );
            $chg = floatval( $c['price_change_percentage_24h'] ?? 0 );
            $breadth_n++;
            if ( $chg > 0 ) $breadth_up++;
            if ( ! $top_gainer || $chg > floatval( $top_gainer['price_change_percentage_24h'] ?? -99 ) ) $top_gainer = $c;
            if ( ! $top_loser  || $chg < floatval( $top_loser['price_change_percentage_24h'] ?? 99 ) )  $top_loser  = $c;
        }
        $breadth_pct = $breadth_n > 0 ? round( $breadth_up / $breadth_n * 100 ) : 0;
        $fg_val   = intval( $fng['data'][0]['value'] ?? 50 );
        $fg_label = $fng['data'][0]['value_classification'] ?? 'Neutral';
        $fg_context = $fg_val < 25  ? 'Extreme Fear readings have historically preceded recoveries as forced selling exhausts itself.'
                    : ( $fg_val > 75 ? 'Extreme Greed often precedes short-term pullbacks as positioning becomes crowded.'
                    : 'Neutral sentiment suggests the market is watching for a clear directional catalyst.' );

        $btc_price  = $btc ? '$' . number_format( floatval( $btc['current_price'] ), 2 )    : 'N/A';
        $btc_chg    = $btc ? floatval( $btc['price_change_percentage_24h'] ?? 0 )             : 0;
        $btc_chg7   = $btc ? floatval( $btc['price_change_percentage_7d_in_currency'] ?? 0 )  : 0;
        $eth_price  = $eth ? '$' . number_format( floatval( $eth['current_price'] ), 2 )    : 'N/A';
        $eth_chg    = $eth ? floatval( $eth['price_change_percentage_24h'] ?? 0 )             : 0;
        $dir = fn($v) => $v >= 0 ? '+' : '';
        $col = fn($v) => $v >= 0 ? 'color:var(--bt-accent)' : 'color:var(--bt-danger)';

        // ── Lead paragraph ────────────────────────────────────────────────────
        $regime = $btc_chg > 3 ? 'expansion' : ( $btc_chg < -3 ? 'contraction' : 'consolidation' );
        $opening = '';
        if ( $btc ) {
            $opening = sprintf(
                '<p>Bitcoin is %s %s%s%% to %s over the past 24 hours, ' .
                'with the broader market showing <strong>%d%% breadth</strong> — %s coins advancing out of the top %d tracked. ' .
                'The 7-day trend stands at <strong>%s%s%%</strong>, placing the market in a <strong>%s</strong> phase. ' .
                'Fear &amp; Greed reads <strong>%d (%s)</strong>. %s</p>',
                $btc_chg >= 0 ? 'up' : 'down',
                $dir($btc_chg), abs(round($btc_chg, 2)),
                $btc_price,
                $breadth_pct,
                $breadth_up,
                $breadth_n,
                $dir($btc_chg7), abs(round($btc_chg7, 2)),
                $regime,
                $fg_val, $fg_label,
                $fg_context
            );
        }

        // ── Top movers analysis ───────────────────────────────────────────────
        $movers_section = '';
        if ( $top_gainer && $top_loser ) {
            $gc = floatval( $top_gainer['price_change_percentage_24h'] ?? 0 );
            $lc = floatval( $top_loser['price_change_percentage_24h'] ?? 0 );
            $movers_section = sprintf(
                '<h2>Top Movers: What Led and What Lagged</h2>' .
                '<p><strong>%s (%s)</strong> led advances with a <span style="%s">+%s%%</span> move to $%s. ' .
                '%s</p>' .
                '<p>On the downside, <strong>%s (%s)</strong> fell <span style="%s">%s%%</span> to $%s. ' .
                'Declines of this magnitude %s typically reflect %s rather than a structural breakdown — watch the $%s level for stabilisation signals.</p>',
                esc_html( $top_gainer['name'] ),
                strtoupper( $top_gainer['symbol'] ),
                $col($gc),
                round($gc, 2),
                number_format( floatval( $top_gainer['current_price'] ), 4 ),
                abs($gc) > 15
                    ? 'Moves of this scale outside BTC/ETH usually point to a specific catalyst — a protocol upgrade, exchange listing, or derivatives liquidation cascade. No catalyst in our feed confirms organic momentum.'
                    : 'The move aligns with broader market breadth, suggesting macro tailwinds rather than an isolated catalyst.',
                esc_html( $top_loser['name'] ),
                strtoupper( $top_loser['symbol'] ),
                $col($lc),
                round($lc, 2),
                number_format( floatval( $top_loser['current_price'] ), 4 ),
                abs($lc) > 10 ? 'of this scale' : 'like this',
                $breadth_pct < 40 ? 'broad risk-off rotation' : 'profit-taking in an otherwise stable market'
                ,
                number_format( floatval( $top_loser['current_price'] ) * 0.95, 4 )
            );
        }

        // ── BTC + ETH deep-dive ───────────────────────────────────────────────
        $btceth_section = '';
        if ( $btc && $eth ) {
            $eth_btc_ratio = floatval($eth['current_price']) > 0 && floatval($btc['current_price']) > 0
                ? round( floatval($eth['current_price']) / floatval($btc['current_price']), 6 ) : 0;
            $btceth_section = sprintf(
                '<h2>Bitcoin and Ethereum: The Core Relationship</h2>' .
                '<p>BTC is trading at <strong>%s</strong> (<span style="%s">%s%s%%</span> 24h | <span style="%s">%s%s%%</span> 7d). ' .
                'The ETH/BTC ratio stands at <strong>%s</strong>, indicating altcoins are %s relative to Bitcoin today. ' .
                'A %s ETH/BTC ratio %s.</p>',
                $btc_price, $col($btc_chg), $dir($btc_chg), abs(round($btc_chg,2)),
                $col($btc_chg7), $dir($btc_chg7), abs(round($btc_chg7,2)),
                $eth_btc_ratio,
                $eth_chg > $btc_chg ? 'outperforming' : 'underperforming',
                $eth_chg > $btc_chg ? 'rising' : 'falling',
                $eth_chg > $btc_chg
                    ? 'signals early altcoin rotation — capital beginning to migrate from Bitcoin into higher-beta assets. This typically precedes a broader alt-season if sustained for 3+ days.'
                    : 'confirms BTC dominance is in play. Capital is consolidating into Bitcoin as the highest-conviction asset during this regime.'
            );
        }

        // ── Forex cross ───────────────────────────────────────────────────────
        $forex_section = '';
        if ( ! empty( $forex['rates'] ) && isset( $forex['rates']['EUR/USD'] ) ) {
            $eur  = $forex['rates']['EUR/USD'];
            $jpy  = $forex['rates']['USD/JPY'] ?? null;
            $eurc = floatval( $eur['change'] ?? 0 );
            $forex_section = '<h2>Macro Context: What the Dollar Is Doing</h2>';
            $forex_section .= sprintf(
                '<p>EUR/USD is at <strong>%s</strong> (<span style="%s">%s%s%%</span>). %s</p>',
                number_format( floatval($eur['rate']), 4 ),
                $col(-$eurc),
                $dir($eurc), abs(round($eurc,3)),
                $eurc < 0
                    ? 'Dollar strength is in play — a headwind for crypto as risk assets typically struggle against a rising DXY.'
                    : 'Dollar weakness creates a constructive backdrop for crypto as global risk appetite expands.'
            );
            if ( $jpy ) {
                $jpyc = floatval( $jpy['change'] ?? 0 );
                $forex_section .= sprintf(
                    '<p>USD/JPY at <strong>%s</strong> (%s%s%%): %s</p>',
                    number_format( floatval($jpy['rate']), 2 ),
                    $dir($jpyc), abs(round($jpyc,3)),
                    $jpyc > 0 ? 'The yen weakening confirms a risk-on tilt in G10 — historically correlated with crypto advances.' : 'Yen strength signals safe-haven flows, which can cap crypto upside in the near term.'
                );
            }
        }

        // ── News that matters ─────────────────────────────────────────────────
        $news_section = '';
        if ( ! empty( $news ) ) {
            $cat_filter = explode( ' ', $topic['category'] ?? '' )[0];
            $relevant   = array_filter( $news, fn($n) => stripos( $n['category'] ?? '', $cat_filter ) !== false );
            if ( empty( $relevant ) ) $relevant = array_slice( $news, 0, 5 );
            $news_section  = '<h2>News That Matters</h2>';
            $news_section .= '<p>Our desk scanned ' . count( $news ) . ' headlines from 24 attributed sources in the last 24 hours. Here are the stories with the most market relevance:</p>';
            foreach ( array_slice( array_values( $relevant ), 0, 4 ) as $item ) {
                $src_note = ! empty( $item['source'] ) ? ' <em>(' . esc_html( $item['source'] ) . ')</em>' : '';
                $news_section .= '<h3><a href="' . esc_url( $item['link'] ) . '" target="_blank" rel="noopener">' . esc_html( $item['title'] ) . '</a>' . $src_note . '</h3>';
                if ( ! empty( $item['description'] ) ) {
                    $news_section .= '<p><strong>Why it matters:</strong> ' . esc_html( $item['description'] ) . ' ';
                    // Add contextual analytical sentence based on category
                    if ( stripos( $item['category'] ?? '', 'crypto' ) !== false || stripos( $item['title'], 'bitcoin' ) !== false ) {
                        $news_section .= 'In a ' . $regime . ' market, this type of headline tends to ' . ( $btc_chg >= 0 ? 'amplify upward momentum if confirmed by volume.' : 'accelerate selling pressure in the short term.' );
                    } elseif ( stripos( $item['category'] ?? '', 'forex' ) !== false ) {
                        $news_section .= 'Forex-driven macro shifts like this have historically taken 24–48 hours to fully price into crypto markets.';
                    }
                    $news_section .= '</p>';
                }
            }
        }

        // ── Desk takeaways ────────────────────────────────────────────────────
        $btc_support = $btc ? '$' . number_format( floatval($btc['current_price']) * 0.95, 0 ) : 'N/A';
        $btc_resist  = $btc ? '$' . number_format( floatval($btc['current_price']) * 1.05, 0 ) : 'N/A';
        $takeaways = '<h2>3 Things to Watch</h2><ol>'
            . '<li><strong>BTC ' . ( $btc_chg >= 0 ? 'resistance' : 'support' ) . ' at ' . ( $btc_chg >= 0 ? $btc_resist : $btc_support ) . '</strong> — '
            . ( $btc_chg >= 0 ? 'A clean break above signals continuation and could pull altcoins into a catch-up rally.' : 'A reclaim of this level in the next 24–48h would signal the correction is shallow and buyers remain in control.' )
            . '</li>'
            . '<li><strong>Market breadth at ' . $breadth_pct . '%</strong> — '
            . ( $breadth_pct > 60 ? 'Broad participation above 60% is the hallmark of a healthy advance. Watch for this to hold on any pullback.' : ( $breadth_pct < 40 ? 'Breadth below 40% means most coins are falling — be selective, favour large-cap positions.' : 'Neutral breadth calls for patience. Wait for a decisive break above 60% or below 40% before adding exposure.' ) )
            . '</li>'
            . '<li><strong>Fear &amp; Greed at ' . $fg_val . ' (' . $fg_label . ')</strong> — '
            . $fg_context
            . '</li></ol>';

        $content = $opening . $movers_section . $btceth_section . $forex_section . $news_section . $takeaways;
        self::create_post( $title, $content, $topic );
    }

    /**
     * v61: Pick a featured image that actually matches the post content.
     * Strategy:
     *   1. Admin setting bt_ai_image_mode controls behavior:
     *      - 'smart'   (default): score news images by topic match; use branded placeholder if no match
     *      - 'branded': always use branded placeholder
     *      - 'news'   : old behavior (any news image) — retained for compatibility
     *      - 'off'    : no featured image
     *   2. Smart mode scores each candidate news item:
     *      - +5  primary keyword match in news title  (e.g. post about BTC → news about Bitcoin)
     *      - +2  secondary keyword match in news title
     *      - +1  keyword match in news excerpt
     *      - -15 blocklist keyword hit (war, conflict, etc. — kills the "drone on crypto" problem)
     *      - -3  stale news (>48h old)
     *   3. Minimum qualifying score = 3. Otherwise fall back to branded placeholder.
     */
    public static function set_featured_image( $post_id ) {
        $mode = get_option( 'bt_ai_image_mode', 'smart' );
        if ( $mode === 'off' ) return;

        $post = get_post( $post_id );
        if ( ! $post ) return;

        // Resolve category slug for placeholder lookup + keyword scoring context
        $cats     = get_the_category( $post_id );
        $cat_slug = ! empty( $cats ) ? $cats[0]->slug : 'market-analysis';

        if ( $mode === 'branded' ) {
            self::attach_branded_placeholder( $post_id, $cat_slug );
            return;
        }

        // 'smart' and 'news' both try news images first
        $news = BT_Widgets::get_json_option( 'fxlm_news_items' );
        $with_images = array_values( array_filter( (array) $news, function( $item ) {
            return ! empty( $item['image'] );
        } ) );

        if ( empty( $with_images ) ) {
            if ( $mode === 'smart' ) self::attach_branded_placeholder( $post_id, $cat_slug );
            return;
        }

        if ( $mode === 'news' ) {
            // Legacy behavior — deterministic offset
            $item = $with_images[ $post_id % count( $with_images ) ];
            self::attach_news_image( $post_id, $item );
            return;
        }

        // --- Smart mode: score each candidate ---
        $title     = $post->post_title;
        $excerpt   = wp_strip_all_tags( $post->post_content );

        // Primary keywords = asset tickers/names found in the post title
        $primary = self::extract_primary_keywords( $title, $cat_slug );
        // Secondary keywords = general topic words from category
        $secondary = self::category_secondary_keywords( $cat_slug );

        // Hard blocklist — these keywords in a news title disqualify the image
        // on crypto/forex posts regardless of other matches. Tragedy / conflict
        // imagery undermines trust on a financial site.
        $blocklist = array(
            'war', 'drone', 'military', 'bomb', 'attack', 'strike',
            'casualt', 'killed', 'death toll', 'terror', 'tragedy',
            'hostage', 'massacre', 'missile', 'invasion',
            'genocide', 'airstrike', 'shooting', 'violence',
        );

        $best_item  = null;
        $best_score = 0;
        $now_ts     = time();

        foreach ( $with_images as $item ) {
            $n_title = strtolower( $item['title'] ?? '' );
            $n_body  = strtolower( ( $item['excerpt'] ?? '' ) . ' ' . ( $item['description'] ?? '' ) );
            $score   = 0;

            // Blocklist check first — instant disqualification for financial posts
            $is_financial = in_array( $cat_slug, array( 'market-analysis', 'crypto-news', 'forex-news', 'bitcoin', 'ethereum', 'altcoins', 'defi-web3' ), true );
            if ( $is_financial ) {
                foreach ( $blocklist as $bad ) {
                    if ( strpos( $n_title, $bad ) !== false || strpos( $n_body, $bad ) !== false ) {
                        $score -= 15;
                        break; // one hit is enough
                    }
                }
            }

            foreach ( $primary as $kw ) {
                $kw_l = strtolower( $kw );
                if ( $kw_l === '' ) continue;
                if ( strpos( $n_title, $kw_l ) !== false ) $score += 5;
                elseif ( strpos( $n_body, $kw_l ) !== false ) $score += 1;
            }
            foreach ( $secondary as $kw ) {
                $kw_l = strtolower( $kw );
                if ( strpos( $n_title, $kw_l ) !== false ) $score += 2;
                elseif ( strpos( $n_body, $kw_l ) !== false ) $score += 1;
            }

            // Recency bonus/penalty
            $item_ts = isset( $item['timestamp'] ) ? intval( $item['timestamp'] )
                     : ( isset( $item['date'] ) ? strtotime( $item['date'] ) : 0 );
            if ( $item_ts > 0 ) {
                $age_hours = ( $now_ts - $item_ts ) / 3600;
                if ( $age_hours > 48 ) $score -= 3;
                if ( $age_hours < 6 )  $score += 1; // fresh bonus
            }

            if ( $score > $best_score ) {
                $best_score = $score;
                $best_item  = $item;
            }
        }

        // Minimum qualifying threshold = 3 (requires at least one title keyword hit)
        if ( $best_item && $best_score >= 3 ) {
            self::attach_news_image( $post_id, $best_item );
            update_post_meta( $post_id, '_bt_featured_source', 'news-smart' );
            update_post_meta( $post_id, '_bt_featured_score', $best_score );
        } else {
            self::attach_branded_placeholder( $post_id, $cat_slug );
            update_post_meta( $post_id, '_bt_featured_source', 'branded-placeholder' );
        }
    }

    /** Download + sideload a news image and set as thumbnail. */
    private static function attach_news_image( $post_id, $item ) {
        if ( empty( $item['image'] ) ) return;
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $item['image'], 15 );
        if ( is_wp_error( $tmp ) ) return;

        $ext  = pathinfo( parse_url( $item['image'], PHP_URL_PATH ), PATHINFO_EXTENSION );
        $ext  = in_array( strtolower( $ext ), array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ) ) ? $ext : 'jpg';
        $file = array(
            'name'     => 'ai-post-' . $post_id . '-' . time() . '.' . $ext,
            'type'     => 'image/' . $ext,
            'tmp_name' => $tmp,
            'error'    => 0,
            'size'     => filesize( $tmp ),
        );
        $id = media_handle_sideload( $file, $post_id );
        @unlink( $tmp );
        if ( ! is_wp_error( $id ) ) {
            set_post_thumbnail( $post_id, $id );
        }
    }

    /**
     * Attach one of the bundled branded category placeholders (v61).
     * Copies from assets/images/placeholders/ into uploads so WP manages it
     * like any other attachment — same deletion lifecycle, SEO-friendly URL.
     */
    private static function attach_branded_placeholder( $post_id, $cat_slug ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $src = BT_DIR . 'assets/images/placeholders/placeholder-' . sanitize_file_name( $cat_slug ) . '.png';
        if ( ! file_exists( $src ) ) {
            // Unknown category → fall back to default.png
            $src = BT_DIR . 'assets/images/placeholders/placeholder-default.png';
            if ( ! file_exists( $src ) ) return;
        }

        $uploads = wp_upload_dir();
        $dest    = trailingslashit( $uploads['path'] ) . 'ai-thumb-' . $cat_slug . '-' . $post_id . '.png';
        if ( ! @copy( $src, $dest ) ) return;

        $ft     = wp_check_filetype( basename( $dest ), null );
        $attach = array(
            'post_mime_type' => $ft['type'],
            'post_title'     => get_the_title( $post_id ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );
        $att_id = wp_insert_attachment( $attach, $dest, $post_id );
        if ( is_wp_error( $att_id ) || ! $att_id ) {
            @unlink( $dest );
            return;
        }
        $meta = wp_generate_attachment_metadata( $att_id, $dest );
        wp_update_attachment_metadata( $att_id, $meta );
        set_post_thumbnail( $post_id, $att_id );
    }

    /**
     * Extract primary keywords (asset tickers + names) from a post title.
     * Returns an array like ['Bitcoin', 'BTC', 'EUR/USD', 'EUR', 'USD'].
     */
    private static function extract_primary_keywords( $title, $cat_slug ) {
        $t = ' ' . $title . ' ';
        $kw = array();

        // Ticker aliases that map to multiple search terms
        $aliases = array(
            'BTC'      => array( 'BTC', 'Bitcoin' ),
            'Bitcoin'  => array( 'BTC', 'Bitcoin' ),
            'ETH'      => array( 'ETH', 'Ethereum' ),
            'Ethereum' => array( 'ETH', 'Ethereum' ),
            'SOL'      => array( 'SOL', 'Solana' ),
            'Solana'   => array( 'SOL', 'Solana' ),
            'XRP'      => array( 'XRP', 'Ripple' ),
            'DOGE'     => array( 'DOGE', 'Dogecoin' ),
            'ADA'      => array( 'ADA', 'Cardano' ),
            'AVAX'     => array( 'AVAX', 'Avalanche' ),
            'LINK'     => array( 'LINK', 'Chainlink' ),
            'DOT'      => array( 'DOT', 'Polkadot' ),
            'MATIC'    => array( 'MATIC', 'Polygon' ),
            'UNI'      => array( 'UNI', 'Uniswap' ),
            'AAVE'     => array( 'AAVE' ),
        );
        foreach ( $aliases as $k => $vals ) {
            if ( preg_match( '/\b' . preg_quote( $k, '/' ) . '\b/i', $t ) ) {
                foreach ( $vals as $v ) $kw[] = $v;
            }
        }

        // Forex pairs — pick up "EUR/USD", "USD/JPY" etc. and split
        if ( preg_match_all( '/\b([A-Z]{3})\s*\/\s*([A-Z]{3})\b/', $title, $m ) ) {
            foreach ( $m[0] as $i => $pair ) {
                $kw[] = str_replace( ' ', '', $pair );
                $kw[] = $m[1][ $i ];
                $kw[] = $m[2][ $i ];
            }
        }

        // Category-tied default keywords if nothing found
        if ( empty( $kw ) ) {
            $defaults = array(
                'forex-news'      => array( 'forex', 'currency', 'dollar', 'euro' ),
                'crypto-news'     => array( 'crypto', 'cryptocurrency', 'blockchain' ),
                'bitcoin'         => array( 'Bitcoin', 'BTC' ),
                'ethereum'        => array( 'Ethereum', 'ETH' ),
                'defi-web3'       => array( 'DeFi', 'Web3', 'DEX', 'staking' ),
                'altcoins'        => array( 'altcoin', 'crypto' ),
                'market-analysis' => array( 'market', 'crypto', 'Bitcoin', 'Ethereum' ),
                'education'       => array( 'crypto', 'trading', 'guide' ),
            );
            if ( isset( $defaults[ $cat_slug ] ) ) $kw = $defaults[ $cat_slug ];
        }

        return array_values( array_unique( $kw ) );
    }

    /** Broad topic keywords for each category — used for secondary scoring. */
    private static function category_secondary_keywords( $cat_slug ) {
        $map = array(
            'market-analysis' => array( 'market', 'price', 'analysis', 'rally', 'selloff', 'rally', 'trading' ),
            'crypto-news'     => array( 'crypto', 'token', 'coin', 'blockchain', 'exchange' ),
            'forex-news'      => array( 'dollar', 'euro', 'pound', 'yen', 'fed', 'ecb', 'rate', 'central bank' ),
            'bitcoin'         => array( 'BTC', 'halving', 'hashrate', 'mining', 'ETF' ),
            'ethereum'        => array( 'ETH', 'gas', 'layer', 'staking', 'validator' ),
            'defi-web3'       => array( 'TVL', 'liquidity', 'yield', 'protocol', 'governance' ),
            'altcoins'        => array( 'token', 'alt', 'meme', 'layer' ),
            'education'       => array( 'guide', 'tutorial', 'beginner', 'explained' ),
        );
        return $map[ $cat_slug ] ?? array();
    }
    /* ======================================================================
     * AI MARKET ANALYSIS v112.0 — per-asset on-demand deep-dive reports
     * ====================================================================== */

    /** Cache TTL — minimum gap between regenerations per asset (seconds). */
    const ANALYSIS_CACHE_TTL  = 3600;  // 1 hour
    /** Post meta key storing the cached analysis HTML. */
    const ANALYSIS_META_KEY   = '_bt_asset_analysis_html';
    /** Post meta key storing the generation timestamp. */
    const ANALYSIS_META_TS    = '_bt_asset_analysis_ts';
    /** WP category name for auto-created analysis posts. */
    const ANALYSIS_CATEGORY   = 'AI Market Analysis';

    /* ------------------------------------------------------------------
     * Data assembly for a specific asset
     * ------------------------------------------------------------------ */

    /**
     * Gather all live context for a given asset symbol.
     *
     * @param  string $symbol  e.g. 'BTC', 'ETH', 'EUR/USD'
     * @return array
     */
    public static function assemble_asset_context( string $symbol ): array {
        $sym    = strtoupper( trim( $symbol ) );
        $is_fx  = strpos( $sym, '/' ) !== false;

        // Price data.
        $price_usd = null; $chg_24h = null; $chg_7d = null; $mcap = null; $vol = null; $name = $sym;
        if ( ! $is_fx ) {
            $crypto = BT_Widgets::get_json_option( 'bt_crypto_data' );
            foreach ( $crypto['coins'] ?? array() as $c ) {
                if ( strtoupper( $c['symbol'] ?? '' ) === $sym || strtolower( $c['id'] ?? '' ) === strtolower( $sym ) ) {
                    $price_usd = floatval( $c['current_price'] ?? 0 );
                    $chg_24h   = floatval( $c['price_change_percentage_24h'] ?? 0 );
                    $chg_7d    = floatval( $c['price_change_percentage_7d'] ?? 0 );
                    $mcap      = floatval( $c['market_cap'] ?? 0 );
                    $vol       = floatval( $c['total_volume'] ?? 0 );
                    $name      = $c['name'] ?? $sym;
                    break;
                }
            }
        } else {
            $forex = BT_Widgets::get_json_option( 'bt_forex_data' );
            $rates = $forex['rates'] ?? $forex['pairs'] ?? array();
            if ( isset( $rates[ $sym ] ) ) $price_usd = floatval( $rates[ $sym ] );
        }

        // Fear & Greed.
        $fng = get_option( 'bt_fear_greed_data', array() );

        // Sentiment (48h for this asset's headlines).
        $mood = null;
        if ( class_exists( 'BT_Sentiment' ) ) {
            $mood = BT_Sentiment::get_market_mood( 48 );
        }

        // Recent headlines mentioning this symbol.
        global $wpdb;
        $headlines = array();
        if ( class_exists( 'BT_Database' ) ) {
            $table = $wpdb->prefix . 'bt_news_items';
            $like  = '%' . $sym . '%';
            $headlines = $wpdb->get_results( $wpdb->prepare(
                "SELECT title, source, sentiment_score, published_at FROM {$table}
                 WHERE (title LIKE %s OR symbols_mentioned LIKE %s)
                   AND published_at >= %s
                 ORDER BY published_at DESC LIMIT 8",
                $like, $like, gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
            ), ARRAY_A ) ?: array();
        }

        // Recent signals for this symbol.
        $signals = array();
        $all_signals = get_option( 'bt_signal_items', array() );
        foreach ( $all_signals as $s ) {
            if ( stripos( $s['title'] ?? '', $sym ) !== false ) {
                $signals[] = $s;
                if ( count( $signals ) >= 5 ) break;
            }
        }

        // Price history summary (7d from DB).
        $hist_summary = array( 'low_7d' => null, 'high_7d' => null, 'rows' => 0 );
        if ( class_exists( 'BT_Database' ) ) {
            $hist = BT_Database::get_price_history( $sym, 7, '1d' );
            if ( ! empty( $hist ) ) {
                $prices = array_column( $hist, 'price_usd' );
                $hist_summary = array(
                    'low_7d'  => round( min( $prices ), 4 ),
                    'high_7d' => round( max( $prices ), 4 ),
                    'rows'    => count( $hist ),
                );
            }
        }

        return compact( 'sym', 'name', 'is_fx', 'price_usd', 'chg_24h', 'chg_7d',
                        'mcap', 'vol', 'fng', 'mood', 'headlines', 'signals', 'hist_summary' );
    }

    /* ------------------------------------------------------------------
     * Prompt builder
     * ------------------------------------------------------------------ */

    private static function build_asset_analysis_prompt( array $ctx ): string {
        $sym   = $ctx['sym'];
        $name  = $ctx['name'];
        $price = $ctx['price_usd'] !== null ? '$' . number_format( $ctx['price_usd'], $ctx['is_fx'] ? 5 : 2 ) : 'N/A';
        $c24   = $ctx['chg_24h'] !== null ? ( $ctx['chg_24h'] >= 0 ? '+' : '' ) . round( $ctx['chg_24h'], 2 ) . '%' : 'N/A';
        $c7d   = $ctx['chg_7d'] !== null ? ( $ctx['chg_7d'] >= 0 ? '+' : '' ) . round( $ctx['chg_7d'], 2 ) . '%' : 'N/A';
        $mcap  = $ctx['mcap'] ? '$' . number_format( $ctx['mcap'], 0 ) : 'N/A';
        $vol   = $ctx['vol']  ? '$' . number_format( $ctx['vol'], 0 )  : 'N/A';
        $fng   = $ctx['fng']['value'] ?? 'N/A';
        $fng_l = $ctx['fng']['value_classification'] ?? '';
        $mood  = $ctx['mood'] ? $ctx['mood']['label'] . ' (' . number_format( $ctx['mood']['score'], 2 ) . ')' : 'N/A';
        $h7    = $ctx['hist_summary'];
        $range = ( $h7['low_7d'] && $h7['high_7d'] ) ? '$' . $h7['low_7d'] . ' – $' . $h7['high_7d'] : 'N/A';

        $headlines_text = '';
        foreach ( $ctx['headlines'] as $h ) {
            $sent = $h['sentiment_score'] !== null ? ' [sentiment: ' . floatval( $h['sentiment_score'] ) . ']' : '';
            $headlines_text .= '• ' . $h['title'] . $sent . "\n";
        }

        $signals_text = '';
        foreach ( $ctx['signals'] as $s ) {
            $signals_text .= '• ' . $s['title'] . ' — ' . $s['source'] . "\n";
        }

        return <<<PROMPT
You are a senior market analyst at BlockTicker. Write a structured, data-driven market analysis report for {$name} ({$sym}).

LIVE DATA (use every data point provided):
- Current price: {$price}
- 24h change: {$c24}
- 7d change: {$c7d}
- Market cap: {$mcap}
- 24h volume: {$vol}
- 7d range: {$range}
- Fear & Greed Index: {$fng} ({$fng_l})
- News sentiment (48h): {$mood}

RECENT HEADLINES:
{$headlines_text}

RECENT TRADING SIGNALS:
{$signals_text}

REQUIRED STRUCTURE — use these exact HTML <h2> headings, in this order:
<h2>Market Overview</h2>      — price context, where we stand vs 7d range, market cap significance
<h2>Technical Picture</h2>    — what the price action tells us (momentum, vol, key levels)
<h2>Sentiment & News Flow</h2>— F&G reading, news sentiment score, what headlines indicate
<h2>Signal Activity</h2>      — interpret any recent signals above; say "No active signals" if none
<h2>Outlook & Key Levels</h2> — 3 actionable takeaways: support/resistance, catalyst, risk

RULES:
- Write 80–120 words per section. Total 400–600 words.
- Use the actual numbers from the data above — don't invent figures.
- Analytical, authoritative tone. No legal disclaimer. No markdown.
- Return only the HTML content (no doctype, no body tag, no style tags).
PROMPT;
    }

    /* ------------------------------------------------------------------
     * Post creation / update
     * ------------------------------------------------------------------ */

    /**
     * Create or update a WordPress post for this asset analysis.
     * Returns the post ID.
     */
    private static function upsert_asset_analysis_post( string $sym, string $html, string $name ): int {
        // Find existing post with matching slug.
        $slug    = 'bt-analysis-' . sanitize_title( $sym );
        $cat_id  = get_cat_ID( self::ANALYSIS_CATEGORY );
        if ( ! $cat_id ) {
            wp_insert_term( self::ANALYSIS_CATEGORY, 'category' );
            $cat_id = get_cat_ID( self::ANALYSIS_CATEGORY );
        }

        $existing = get_page_by_path( $slug, OBJECT, 'post' );
        $title    = $name . ' (' . $sym . ') — AI Market Analysis — ' . gmdate( 'F j, Y' );

        $disclaimer = '<p class="bt-analysis-disclaimer" style="font-size:12px;color:var(--bt-text-3);border-top:1px solid rgba(255,255,255,.06);margin-top:24px;padding-top:12px;">'
            . 'This analysis is generated by AI and is for informational purposes only. Not financial advice. Always conduct your own research (DYOR).'
            . '</p>';

        $full_html = '<div class="bt-asset-analysis">' . $html . $disclaimer . '</div>';

        $args = array(
            'post_title'    => $title,
            'post_content'  => $full_html,
            'post_status'   => 'publish',
            'post_type'     => 'post',
            'post_name'     => $slug,
            'post_author'   => 1,
            'post_category' => $cat_id ? array( $cat_id ) : array(),
        );

        if ( $existing ) {
            $args['ID'] = $existing->ID;
            $post_id    = wp_update_post( $args );
        } else {
            $post_id = wp_insert_post( $args );
        }

        if ( ! $post_id || is_wp_error( $post_id ) ) return 0;

        // Tags.
        wp_set_post_tags( $post_id, array( $sym, $name, 'AI Analysis', 'Market Analysis' ) );

        // Yoast meta.
        $meta_desc = "AI-generated market analysis for {$name} ({$sym}) — price, technicals, sentiment, and key levels.";
        update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
        update_post_meta( $post_id, '_yoast_wpseo_focuskw', $sym . ' analysis' );

        // Article JSON-LD (injected via post meta, picked up by BT_SEO output_schema).
        $article_schema = array(
            '@context'         => 'https://schema.org',
            '@type'            => 'FinancialProduct',
            'name'             => $name . ' Market Analysis',
            'description'      => $meta_desc,
            'dateModified'     => gmdate( 'c' ),
            'provider'         => array( '@type' => 'Organization', 'name' => get_option( 'bt_site_name', 'BlockTicker' ) ),
        );
        update_post_meta( $post_id, '_bt_article_schema', wp_json_encode( $article_schema ) );

        // Cache the HTML + timestamp in post meta for fast serving.
        update_post_meta( $post_id, self::ANALYSIS_META_KEY, $html );
        update_post_meta( $post_id, self::ANALYSIS_META_TS,  time() );

        /**
         * v113.0: Fire after the post is saved so BT_Social (or any other
         * listener) can generate and auto-publish X threads and Telegram
         * messages for the freshly refreshed analysis.
         *
         * @param int    $post_id  The WP post ID that was created/updated.
         * @param string $sym      Uppercase asset symbol (e.g. BTC, EUR/USD).
         * @param string $name     Human-readable asset name.
         */
        do_action( 'bt_asset_analysis_saved', $post_id, $sym, $name );

        return $post_id;
    }

    /* ------------------------------------------------------------------
     * Public generate method (used by AJAX + shortcode)
     * ------------------------------------------------------------------ */

    /**
     * Generate (or return cached) analysis for a symbol.
     *
     * @param  string $symbol   Asset symbol.
     * @param  bool   $force    Skip cache and regenerate.
     * @return array  { html, post_id, cached, generated_at, error? }
     */
    public static function generate_asset_analysis( string $symbol, bool $force = false ): array {
        $sym  = strtoupper( trim( $symbol ) );
        $slug = 'bt-analysis-' . sanitize_title( $sym );

        // Check rate limit / cache.
        $existing  = get_page_by_path( $slug, OBJECT, 'post' );
        $cached_ts = $existing ? (int) get_post_meta( $existing->ID, self::ANALYSIS_META_TS, true ) : 0;
        $cached_html = $existing ? get_post_meta( $existing->ID, self::ANALYSIS_META_KEY, true ) : '';

        if ( ! $force && $cached_html && ( time() - $cached_ts ) < self::ANALYSIS_CACHE_TTL ) {
            return array(
                'html'         => $cached_html,
                'post_id'      => $existing->ID,
                'post_url'     => get_permalink( $existing->ID ),
                'cached'       => true,
                'generated_at' => $cached_ts,
            );
        }

        // Generate fresh analysis.
        $ctx    = self::assemble_asset_context( $sym );
        $prompt = self::build_asset_analysis_prompt( $ctx );
        $html   = self::call_ai( $prompt );

        if ( ! $html ) {
            return array( 'error' => 'AI generation failed. Check your API key in BlockTicker → Settings.' );
        }

        $name    = $ctx['name'];
        $post_id = self::upsert_asset_analysis_post( $sym, $html, $name );

        return array(
            'html'         => $html,
            'post_id'      => $post_id,
            'post_url'     => $post_id ? get_permalink( $post_id ) : '',
            'cached'       => false,
            'generated_at' => time(),
        );
    }

    /* ------------------------------------------------------------------
     * AJAX handler
     * ------------------------------------------------------------------ */

    public static function ajax_generate_asset_analysis() {
        // Rate limit: 1 generation per symbol per 15 minutes per IP for guests.
        $sym = sanitize_text_field( strtoupper( $_POST['symbol'] ?? '' ) );
        if ( ! $sym ) wp_send_json_error( 'Symbol required.' );

        $nonce_action = 'bt_asset_analysis_' . $sym;
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', $nonce_action ) ) {
            wp_send_json_error( 'Security check failed.' );
        }

        $force  = (bool) ( $_POST['force'] ?? false ) && current_user_can( 'manage_options' );
        $result = self::generate_asset_analysis( $sym, $force );

        if ( ! empty( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }

        wp_send_json_success( array(
            'html'         => $result['html'],
            'post_url'     => $result['post_url'] ?? '',
            'cached'       => $result['cached'],
            'generated_at' => $result['generated_at'],
            'age_min'      => round( ( time() - $result['generated_at'] ) / 60 ),
        ) );
    }

    /* ------------------------------------------------------------------
     * Shortcode: [bt_ai_analysis_v2]
     * ------------------------------------------------------------------ */

    /**
     * [bt_ai_analysis_v2 symbol="BTC" show_refresh="1" height="auto"]
     *
     * Displays the cached analysis for the given symbol, with a
     * "Refresh Analysis" button that triggers AJAX regeneration.
     *
     * Attributes:
     *   symbol        Asset symbol (required)
     *   show_refresh  1|0 — show the Regenerate button (default: 1)
     *   show_post_link 1|0 — show "View full post" link (default: 1)
     *   autoload      1|0 — auto-trigger analysis on page load if none cached (default: 0)
     */
    public static function sc_ai_analysis_v2( $atts ) {
        $a = shortcode_atts( array(
            'symbol'         => 'BTC',
            'show_refresh'   => 1,
            'show_post_link' => 1,
            'autoload'       => 0,
        ), $atts );

        $sym   = strtoupper( sanitize_text_field( $a['symbol'] ) );
        $uid   = 'bt-aa2-' . sanitize_html_class( $sym );
        $nonce = wp_create_nonce( 'bt_asset_analysis_' . $sym );
        $ajax  = esc_url( admin_url( 'admin-ajax.php' ) );

        // Try to serve cached analysis immediately (server-side).
        $initial = self::generate_asset_analysis( $sym, false );
        $has_cached = ! empty( $initial['html'] ) && empty( $initial['error'] );
        $age_min    = $has_cached ? round( ( time() - $initial['generated_at'] ) / 60 ) : null;

        ob_start(); ?>
        <div class="bt-aa2-wrap" id="<?php echo esc_attr( $uid ); ?>"
             data-sym="<?php echo esc_attr( $sym ); ?>"
             data-nonce="<?php echo esc_attr( $nonce ); ?>"
             data-ajax="<?php echo $ajax; ?>"
             data-autoload="<?php echo $a['autoload'] ? '1' : '0'; ?>">

            <div class="bt-aa2-header">
                <div class="bt-aa2-badge">🤖 AI Analysis</div>
                <span class="bt-aa2-sym"><?php echo esc_html( $sym ); ?></span>
                <?php if ( $has_cached ) : ?>
                <span class="bt-aa2-age" id="<?php echo esc_attr( $uid ); ?>-age">
                    <?php echo esc_html( $age_min < 1 ? 'Just generated' : "Generated {$age_min} min ago" ); ?>
                </span>
                <?php endif; ?>
                <span class="bt-aa2-spacer"></span>
                <?php if ( $a['show_refresh'] ) : ?>
                <button class="bt-aa2-refresh-btn" id="<?php echo esc_attr( $uid ); ?>-btn">
                    ↻ <?php esc_html_e( 'Refresh Analysis', 'blockticker' ); ?>
                </button>
                <?php endif; ?>
            </div>

            <div class="bt-aa2-content" id="<?php echo esc_attr( $uid ); ?>-content">
                <?php if ( $has_cached ) : ?>
                    <?php echo wp_kses_post( $initial['html'] ); ?>
                <?php elseif ( $a['autoload'] ) : ?>
                    <div class="bt-aa2-loading" id="<?php echo esc_attr( $uid ); ?>-loading">
                        <div class="bt-aa2-spinner"></div>
                        <p><?php esc_html_e( 'Generating analysis…', 'blockticker' ); ?></p>
                    </div>
                <?php else : ?>
                    <div class="bt-aa2-prompt">
                        <p>📊 <?php printf( esc_html__( 'AI analysis for %s has not been generated yet.', 'blockticker' ), esc_html( $sym ) ); ?></p>
                        <?php if ( $a['show_refresh'] ) : ?>
                        <button class="bt-aa2-generate-btn">
                            🤖 <?php esc_html_e( 'Generate Analysis Now', 'blockticker' ); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ( $has_cached && $a['show_post_link'] && ! empty( $initial['post_url'] ) ) : ?>
            <div class="bt-aa2-footer">
                <a href="<?php echo esc_url( $initial['post_url'] ); ?>" class="bt-aa2-post-link">
                    📄 <?php esc_html_e( 'View full analysis post', 'blockticker' ); ?> →
                </a>
            </div>
            <?php endif; ?>
        </div>

        <style>
        .bt-aa2-wrap{font-family:inherit;margin:16px 0;border:1px solid rgba(255,255,255,.08);border-radius:0;overflow:hidden}
        .bt-aa2-header{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 18px;background:rgba(0,255,102,.06);border-bottom:1px solid rgba(255,255,255,.06)}
        .bt-aa2-badge{font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;background:rgba(0,255,102,.2);color:var(--bt-accent);border:1px solid rgba(0,255,102,.3)}
        .bt-aa2-sym{font-size:15px;font-weight:800;color:var(--bt-text)}
        .bt-aa2-age{font-size:11px;color:var(--bt-text-3)}
        .bt-aa2-spacer{flex:1}
        .bt-aa2-refresh-btn,.bt-aa2-generate-btn{padding:6px 14px;border-radius:0;font-size:12px;font-weight:700;cursor:pointer;border:1px solid var(--bt-accent)40;background:transparent;color:var(--bt-accent);transition:all .15s;font-family:inherit}
        .bt-aa2-refresh-btn:hover,.bt-aa2-generate-btn:hover{background:var(--bt-accent);color:#0b0f1a}
        .bt-aa2-refresh-btn:disabled{opacity:.5;cursor:wait}
        .bt-aa2-content{padding:20px 24px;min-height:60px}
        .bt-aa2-content h2{font-size:15px;font-weight:700;color:var(--bt-accent);margin:18px 0 8px;border-bottom:1px solid rgba(0,255,102,.15);padding-bottom:6px}
        .bt-aa2-content h2:first-child{margin-top:0}
        .bt-aa2-content p{font-size:14px;line-height:1.7;color:#cbd5e1;margin:0 0 12px}
        .bt-aa2-loading{display:flex;align-items:center;gap:12px;color:var(--bt-text-3);padding:16px 0}
        .bt-aa2-spinner{width:20px;height:20px;border:2px solid rgba(0,255,102,.2);border-top-color:var(--bt-accent);border-radius:50%;animation:bt-spin 0.7s linear infinite;flex-shrink:0}
        @keyframes bt-spin{to{transform:rotate(360deg)}}
        .bt-aa2-prompt{text-align:center;padding:24px;color:var(--bt-text-3)}
        .bt-aa2-prompt p{margin-bottom:12px}
        .bt-aa2-footer{padding:10px 24px;border-top:1px solid rgba(255,255,255,.06);text-align:right}
        .bt-aa2-post-link{font-size:12px;color:var(--bt-text-3);text-decoration:none}
        .bt-aa2-post-link:hover{color:var(--bt-accent)}
        .bt-analysis-disclaimer{font-size:11px!important;color:var(--bt-text-3)!important}
        </style>

        <script>
        (function(){
            var wrap   = document.getElementById(<?php echo wp_json_encode( $uid ); ?>);
            if(!wrap) return;
            var sym    = wrap.dataset.sym;
            var nonce  = wrap.dataset.nonce;
            var ajax   = wrap.dataset.ajax;
            var content = document.getElementById(<?php echo wp_json_encode( $uid . '-content' ); ?>);
            var ageEl  = document.getElementById(<?php echo wp_json_encode( $uid . '-age' ); ?>);

            function doGenerate(force){
                var btn = wrap.querySelector('.bt-aa2-refresh-btn');
                if(btn){ btn.disabled=true; btn.textContent='↻ Generating…'; }
                content.innerHTML = '<div class="bt-aa2-loading"><div class="bt-aa2-spinner"></div><p>Generating AI analysis for '+sym+'…</p></div>';

                var fd = new FormData();
                fd.append('action','bt_generate_asset_analysis');
                fd.append('symbol',sym);
                fd.append('nonce',nonce);
                if(force) fd.append('force','1');

                fetch(ajax,{method:'POST',body:fd})
                .then(function(r){return r.json();})
                .then(function(d){
                    if(d.success){
                        content.innerHTML = d.data.html;
                        if(ageEl) ageEl.textContent = 'Just generated';
                    } else {
                        content.innerHTML = '<p style="color:#ef4444">❌ '+( d.data||'Generation failed')+'</p>';
                    }
                    if(btn){ btn.disabled=false; btn.textContent='↻ Refresh Analysis'; }
                })
                .catch(function(e){
                    content.innerHTML = '<p style="color:#ef4444">❌ Request failed</p>';
                    if(btn){ btn.disabled=false; btn.textContent='↻ Refresh Analysis'; }
                });
            }

            wrap.querySelector('.bt-aa2-refresh-btn')?.addEventListener('click', function(){ doGenerate(false); });
            wrap.querySelector('.bt-aa2-generate-btn')?.addEventListener('click', function(){ doGenerate(false); });

            if(wrap.dataset.autoload==='1' && !content.querySelector('p,h2,h3')) doGenerate(false);
        })();
        </script>
        <?php
        return ob_get_clean();
    }


}

