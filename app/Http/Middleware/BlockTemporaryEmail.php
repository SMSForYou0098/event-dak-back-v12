<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Comprehensive Temporary/Disposable Email Blocker
 * 
 * This middleware blocks temporary email addresses using multiple methods:
 * 1. Direct domain blocklist
 * 2. MX server IP blocklist (most effective - blocks thousands of domains at once)
 * 3. MX hostname pattern matching
 * 4. Optional API fallback
 * 
 * Data sourced from:
 * - UserCheck.com (800+ providers, 200,000+ domains tracked)
 * - GitHub disposable-email-domains
 * - infiniteloopltd/TempEmailDomainMXRecords
 * 
 * Last updated: December 2024
 */
class BlockTemporaryEmail
{
    /**
     * Blocked IP ranges (CIDR notation or prefix)
     * This blocks entire subnets used by temp mail providers
     */
    protected $blockedIpRanges = [
        '91.195.240.',      // SEDO GmbH - temp-mail.io, 10minute-mail.org
        '91.195.241.',      // SEDO GmbH range
        '165.22.204.',      // DigitalOcean temp mail servers
        '165.22.205.',      // DigitalOcean temp mail servers
        '165.22.206.',      // DigitalOcean temp mail servers
        '137.184.154.',     // DigitalOcean temp mail servers
    ];

    /**
     * Known temporary email MX server IP addresses
     * Blocking by IP catches ALL domains using that mail server
     * 
     * Example: temp-mail.org uses IP 161.35.255.145 for 2,304+ domains
     * By blocking this single IP, we block all 2,304 domains automatically
     */
    protected $blockedMxIps = [
        // temp-mail.org (2,304+ domains) - httpsu.com, badfist.com, datehype.com, etc.
        '161.35.255.145',
        '24.199.66.37',
        '142.132.166.12',
        '116.202.9.167',

        // tempmail.lol (60,039+ domains) - chessgamingworld.com, undeadbank.com, etc.
        '46.62.148.222',

        // guerrillamail.com (12+ domains) - sharklasers.com, grr.la, pokemail.net, etc.
        '178.162.170.166',
        '168.119.142.36',
        '198.143.169.10',

        // mailinator.com and aliases
        '104.218.55.59',
        '69.164.207.250',
        '45.33.83.197',

        // 10minutemail.com
        '74.208.4.200',
        '74.208.4.201',

        // tempm.com (42,091+ domains)
        '185.17.120.80',
        '185.17.120.81',

        // dropmail.me (20,233+ domains)
        '167.172.171.127',
        '167.172.171.128',

        // emailfake.com (31,967+ domains)
        '104.21.64.1',
        '172.67.180.1',

        // mail-fake.com (19,371+ domains)
        '104.21.48.1',
        '172.67.160.1',

        // generator.email (14,090+ domains)
        '104.21.32.1',
        '172.67.144.1',

        // mail-temp.com (12,388+ domains)
        '77.88.21.249',
        '77.88.21.158',

        // yopmail.com (1,666+ domains)
        '185.106.208.100',
        '213.186.33.68',

        // mohmal.com
        '185.147.214.141',

        // fakeinbox.com
        '104.24.96.1',
        '104.24.97.1',

        // trashmail.com
        '91.250.86.53',
        '85.25.13.241',

        // getairmail.com
        '104.131.67.93',

        // throwawaymail.com
        '185.17.120.80',

        // temp-mails.org
        '104.21.80.1',

        // internxt.com (5,226+ domains)
        '104.22.64.1',
        '172.67.192.1',

        // Additional known temp mail IPs
        '37.97.167.105',    // temporarymailaddress.com
        '78.46.205.76',     // tempemail.biz
        '80.67.18.126',     // tempmaildemo.com
        '103.224.212.34',   // tempemail.co.za, park-mx.above.com

        // tempmailo.com associated MX servers
        '38.143.66.193',    // mx.plingest.com
        '142.93.233.86',    // em4.mainnetmail.com  
        '165.22.206.176',   // mx4.mainnetmail.com
        '137.184.154.224',  // em4.catchservers.com
        '165.22.201.68',    // mx4.catchservers.net
        '164.90.194.37',    // mail.eye-mail.net
        '165.22.205.213',   // em4.rejecthost.com
        '165.22.204.99',    // srv4.rejecthost.com
        '153.92.214.129',   // tutuapp.bid

        // temp-mail.io / 10minute-mail.org (SEDO GmbH range - Germany)
        '91.195.240.12',
        '91.195.240.123',
        '91.195.240.1',
        '91.195.240.2',
        '91.195.240.3',
        '91.195.240.4',
        '91.195.240.5',
        '91.195.240.10',
        '91.195.240.11',
        '91.195.240.13',
        '91.195.240.14',
        '91.195.240.15',
        '91.195.240.20',
        '91.195.240.21',
        '91.195.240.22',
        '91.195.240.23',
        '91.195.240.24',
        '91.195.240.25',
        '91.195.240.100',
        '91.195.240.101',
        '91.195.240.102',
        '91.195.240.103',
        '91.195.240.104',
        '91.195.240.105',
        '91.195.240.110',
        '91.195.240.111',
        '91.195.240.112',
        '91.195.240.120',
        '91.195.240.121',
        '91.195.240.122',
        '91.195.240.124',
        '91.195.240.125',
        '91.195.240.130',
        '91.195.240.140',
        '91.195.240.150',
        '91.195.240.200',
        '91.195.240.201',
        '91.195.240.202',
        '91.195.240.210',
        '91.195.240.220',
        '91.195.240.230',
        '91.195.240.240',
        '91.195.240.250',

        // Other temp mail services
        '82.192.80.80',
        '84.32.84.34',

        // Yandex Mail (used by many temp mail services like tempmailo.com)
        // Safe to block for Indian users
        '77.88.21.249',
        '77.88.21.158',
        '77.88.21.37',
        '77.88.21.38',
        '77.88.21.136',
        '77.88.21.25',
        '77.88.21.89',
        '77.88.21.90',
        '77.88.21.91',
        '87.250.250.38',
        '87.250.250.89',
        '87.250.250.90',
        '87.250.250.91',
        '93.158.134.89',
        '93.158.134.90',
        '93.158.134.91',
        '213.180.193.89',
        '213.180.193.90',
        '213.180.193.91',

        // Mail.ru (Russian - safe to block for Indian users)
        '94.100.180.31',
        '94.100.180.32',
        '94.100.180.33',
        '217.69.139.150',
        '217.69.139.151',
        '217.69.139.152',

        // Rambler.ru (Russian)
        '81.19.77.100',
        '81.19.77.101',

        // cock.li (privacy/temp mail service)
        '88.80.30.89',
        '199.195.250.77',

        // ProtonMail (often used for throwaway - optional, comment out if needed)
        // '185.70.40.101',
        // '185.70.40.102',
    ];

    /**
     * Known temporary email domains
     * This list contains major temp mail providers and their aliases
     */
    protected $blockedDomains = [
        // Major providers
        'temp-mail.org',
        'tempmail.com',
        'tempmail.net',
        'temp-mail.ru',
        'temp-mail.de',
        'tempmail.lol',
        'tempm.com',
        'mail-temp.com',
        'temp-mail.io',

        // tempmailo.com and its domains (51+ domains)
        'tempmailo.com',
        'tempmailo.org',
        'forexzig.com',
        'thetechnext.net',
        'test.thetechnext.net',
        'asciibinder.net',
        'dreamclarify.org',
        'myfxspot.com',
        'logsmarter.net',
        'azuretechtalk.net',
        'polkaroad.net',
        'molecule.ink',
        'velvet-mag.lat',
        'clip.lat',
        'citmo.net',
        'pelagius.net',
        'closetab.email',
        'imagepoet.net',
        'hexi.pics',
        'socam.me',
        'clout.wiki',
        'tutuapp.bid',
        'afia.pro',
        'plingest.com',
        'mainnetmail.com',
        'catchservers.com',
        'catchservers.net',
        'eye-mail.net',
        'rejecthost.com',

        // Guerrillamail network
        'guerrillamail.com',
        'guerrillamail.net',
        'guerrillamail.org',
        'guerrillamail.biz',
        'guerrillamail.de',
        'guerrillamail.info',
        'guerrillamailblock.com',
        'sharklasers.com',
        'grr.la',
        'pokemail.net',
        'spam4.me',

        // Mailinator network
        'mailinator.com',
        'mailinator.net',
        'mailinator.org',
        'mailinator2.com',
        'mailinater.com',
        'mailinator.us',

        // 10minutemail variants
        '10minutemail.com',
        '10minutemail.net',
        '10minutemail.org',
        '10minutemail.de',
        '10minutemail.be',
        '10minutemail.co.za',
        '10minutemail.co.uk',
        '10minutemail.cf',
        '10minutemail.ga',
        '10minutemail.gq',
        '10minutemail.ml',
        '10minutesmail.com',
        '10minutesmail.fr',
        '10minutemail.nl',
        '10minutemail.pro',
        '10minutemail.us',
        '10minutemailbox.com',

        // YOPmail network
        'yopmail.com',
        'yopmail.fr',
        'yopmail.net',
        'cool.fr.nf',
        'jetable.fr.nf',
        'courriel.fr.nf',
        'moncourrier.fr.nf',
        'monemail.fr.nf',
        'monmail.fr.nf',
        'nospam.ze.tc',
        'nomail.xl.cx',
        'mega.zik.dj',
        'speed.1s.fr',

        // Disposable email services
        'dispostable.com',
        'disposableemailaddresses.com',
        'disposableinbox.com',
        'disposablemails.com',
        'disposable.site',
        'disposable-email.ml',
        'dispose.it',
        'disposeamail.com',
        'disposemymail.com',

        // Trash/Junk mail
        'trashmail.com',
        'trashmail.de',
        'trashmail.net',
        'trashmail.org',
        'trashmail.ws',
        'trashemail.de',
        'trashymail.com',
        'trashymail.net',

        // Fake/Temp mail
        'fakeinbox.com',
        'fakemailgenerator.com',
        'fakemail.fr',
        'fake-mail.cf',
        'fakemailgenerator.net',
        'email-fake.com',
        'mail-fake.com',
        'emailfake.com',

        // Throwaway email
        'throwawaymail.com',
        'throwaway.email',
        'throam.com',

        // GetNada
        'getnada.com',
        'nada.email',
        'nada.ltd',

        // Mohmal
        'mohmal.com',
        'mohmal.im',
        'mohmal.in',
        'mohmal.tech',

        // Other popular services
        'dropmail.me',
        'emailondeck.com',
        'getairmail.com',
        'mintemail.com',
        'tempail.com',
        'tempr.email',
        'tempsky.com',
        'tempemail.biz',
        'tempemail.co.za',
        'tempemail.com',
        'tempemail.net',
        'tempmailer.com',
        'tempmailer.de',
        'temporaryemail.net',
        'temporarymailaddress.com',
        'mailcatch.com',
        'mailexpire.com',
        'maildrop.cc',
        'mailnesia.com',
        'mailnull.com',
        'mailsac.com',
        'mailscrap.com',
        'mailslurp.com',
        'mytrashmail.com',
        'inboxbear.com',
        'inboxalias.com',
        'spamgourmet.com',
        'spamcowboy.com',
        'spambox.us',
        'spamfree24.org',
        'spamex.com',
        'antispam.de',
        'anonymbox.com',
        'anonbox.net',
        'anonmails.de',
        'anonymmail.net',
        'armyspy.com',
        'cuvox.de',
        'dayrep.com',
        'einrot.com',
        'fleckens.hu',
        'gustr.com',
        'jourrapide.com',
        'rhyta.com',
        'superrito.com',
        'teleworm.us',
        'tempomail.fr',
        'trbvm.com',
        'dodgeit.com',
        'dodgit.com',
        'dodgit.org',
        'e4ward.com',
        'emailthe.net',
        'emailtmp.com',
        'emkei.cf',
        'ephemail.net',
        'example.com',
        'explodemail.com',
        'fastacura.com',
        'fastchevy.com',
        'fastchrysler.com',
        'fastkawasaki.com',
        'fastmazda.com',
        'fastmitsubishi.com',
        'fastnissan.com',
        'fastsubaru.com',
        'fastsuzuki.com',
        'fasttoyota.com',
        'fastyamaha.com',
        'guerrillamail.org',
        'imgof.com',
        'imgv.de',
        'incognitomail.com',
        'incognitomail.net',
        'incognitomail.org',
        'ipoo.org',
        'irish2me.com',
        'jetable.com',
        'kasmail.com',
        'kaspop.com',
        'keepmymail.com',
        'killmail.com',
        'killmail.net',
        'klassmaster.com',
        'klassmaster.net',
        'klzlv.com',
        'kulturbetrieb.info',
        'kurzepost.de',
        'lifebyfood.com',
        'link2mail.net',
        'litedrop.com',
        'lol.ovpn.to',
        'lroid.com',
        'mail.by',
        'mail.mezimages.net',
        'mail.zp.ua',
        'mail114.net',
        'mail15.com',
        'mail333.com',
        'mail4trash.com',
        'mailbidon.com',
        'mailblocks.com',
        'mailcatch.com',
        'mailde.de',
        'mailde.info',
        'maidlow.info',
        'maildu.de',
        'maileater.com',
        'mailed.in',
        'mailf5.com',
        'mailfa.tk',
        'mailfork.com',
        'mailfreeonline.com',
        'mailguard.me',
        'mailhz.me',
        'mailimate.com',
        'mailin8r.com',
        'mailinblack.com',
        'mailincubator.com',
        'mailita.tk',
        'mailjunk.cf',
        'mailjunk.ga',
        'mailjunk.gq',
        'mailjunk.ml',
        'mailjunk.tk',
        'mailmate.com',
        'mailme.gq',
        'mailme.ir',
        'mailme.lv',
        'mailme24.com',
        'mailmetrash.com',
        'mailmoat.com',
        'mailnator.com',
        'mailnull.com',
        'mailpick.biz',
        'mailrock.biz',
        'mailseal.de',
        'mailshell.com',
        'mailsiphon.com',
        'mailslapping.com',
        'mailsource.info',
        'mailtemp.info',
        'mailtothis.com',
        'mailzilla.com',
        'mailzilla.org',
        'makemetheking.com',
        'manifestgenerator.com',
        'manybrain.com',
        'mbx.cc',
        'mega.zik.dj',
        'meinspamschutz.de',
        'meltmail.com',
        'messagebeamer.de',
        'mezimages.net',
        'mierdamail.com',
        'ministry-of-silly-walks.de',
        'mintemail.com',
        'misterpinball.de',
        'moncourrier.fr.nf',
        'monemail.fr.nf',
        'monmail.fr.nf',
        'monumentmail.com',
        'ms9.mailslite.com',
        'msb.minsmail.com',
        'msg.mailslite.com',
        'mspeciosa.com',
        'mvrht.com',
        'mx0.wwwnew.eu',
        'my10minutemail.com',
        'mycleaninbox.net',
        'mymail-in.net',
        'mymailoasis.com',
        'mynetstore.de',
        'mypacks.net',
        'mypartyclip.de',
        'myphantomemail.com',
        'myspaceinc.com',
        'myspaceinc.net',
        'myspacepimpedup.com',
        'mytrashmail.com',
        'mytrashemail.com',
        'neomailbox.com',
        'nepwk.com',
        'nervmich.net',
        'nervtmansen.net',
        'netmails.com',
        'netmails.net',
        'netzidiot.de',
        'neverbox.com',
        'nice-4u.com',
        'nincsmail.hu',
        'nmail.cf',
        'nobulk.com',
        'noclickemail.com',
        'nogmailspam.info',
        'nomail.xl.cx',
        'nomail2me.com',
        'nomorespamemails.com',
        'nospam.ze.tc',
        'nospam4.us',
        'nospamfor.us',
        'nospammail.net',
        'nospamthanks.info',
        'notmailinator.com',
        'notsharingmy.info',
        'nowhere.org',
        'nowmymail.com',
        'ntlhelp.net',
        'nurfuerspam.de',
        'nus.edu.sg',
        'nwldx.com',
        'o2.co.uk',
        'o2.pl',
        'objectmail.com',
        'obobbo.com',
        'odaymail.com',
        'odnorazovoe.ru',
        'ohaaa.de',
        'omail.pro',
        'oneoffemail.com',
        'oneoffmail.com',
        'onewaymail.com',
        'onlatedotcom.info',
        'online.ms',
        'oopi.org',
        'opayq.com',
        'ordinaryamerican.net',
        'otherinbox.com',
        'ourklips.com',
        'outlawspam.com',
        'ovpn.to',
        'owlpic.com',
        'pancakemail.com',
        'pjjkp.com',
        'plexolan.de',
        'poczta.onet.pl',
        'politikerclub.de',
        'poofy.org',
        'pookmail.com',
        'pop3.xyz',
        'privacy.net',
        'privy-mail.com',
        'privymail.de',
        'proxymail.eu',
        'prtnx.com',
        'punkass.com',
        'put2.net',
        'putthisinyourspamdatabase.com',
        'qq.com',
        'quickinbox.com',
        'quickmail.nl',
        'rainmail.biz',
        'rcpt.at',
        'reallymymail.com',
        'receiveee.chickenkiller.com',
        'receiveee.com',
        'recursor.net',
        'recyclemail.dk',
        'regbypass.com',
        'regbypass.comsafe-mail.net',
        'rejectmail.com',
        'remail.cf',
        'remail.ga',
        'rhyta.com',
        'rklips.com',
        'rmqkr.net',
        'royal.net',
        'rppkn.com',
        'rtrtr.com',
        's0ny.net',
        'safe-mail.net',
        'safersignup.de',
        'safetymail.info',
        'safetypost.de',
        'sandelf.de',
        'saynotospams.com',
        'schafmail.de',
        'schrott-email.de',
        'secretemail.de',
        'secure-mail.biz',
        'selfdestructingmail.com',
        'sendspamhere.com',
        'senseless-entertainment.com',
        'server.ms.selfip.com',
        'sharklasers.com',
        'shieldemail.com',
        'shiftmail.com',
        'shitmail.me',
        'shortmail.net',
        'shut.name',
        'shut.ws',
        'sibmail.com',
        'sinnlos-mail.de',
        'siteposter.net',
        'skeefmail.com',
        'slaskpost.se',
        'slopsbox.com',
        'smashmail.de',
        'smellfear.com',
        'snakemail.com',
        'sneakemail.com',
        'sneakmail.de',
        'snkmail.com',
        'sofimail.com',
        'sofort-mail.de',
        'sogetthis.com',
        'sohu.com',
        'solvemail.info',
        'soodonims.com',
        'spam.la',
        'spam.su',
        'spam4.me',
        'spamail.de',
        'spamavert.com',
        'spambob.com',
        'spambob.net',
        'spambob.org',
        'spambog.com',
        'spambog.de',
        'spambog.net',
        'spambog.ru',
        'spambox.info',
        'spambox.irishspringrealty.com',
        'spambox.us',
        'spamcannon.com',
        'spamcannon.net',
        'spamcero.com',
        'spamcon.org',
        'spamcorptastic.com',
        'spamcowboy.com',
        'spamcowboy.net',
        'spamcowboy.org',
        'spamday.com',
        'spamex.com',
        'spamfighter.cf',
        'spamfighter.ga',
        'spamfighter.gq',
        'spamfighter.ml',
        'spamfighter.tk',
        'spamfree.eu',
        'spamfree24.com',
        'spamfree24.de',
        'spamfree24.eu',
        'spamfree24.info',
        'spamfree24.net',
        'spamfree24.org',
        'spamgoes.in',
        'spamgourmet.com',
        'spamgourmet.net',
        'spamgourmet.org',
        'spamherelots.com',
        'spamhereplease.com',
        'spamhole.com',
        'spamify.com',
        'spaminator.de',
        'spamkill.info',
        'spaml.com',
        'spaml.de',
        'spammotel.com',
        'spamobox.com',
        'spamoff.de',
        'spamsalad.in',
        'spamslicer.com',
        'spamspot.com',
        'spamstack.net',
        'spamthis.co.uk',
        'spamthisplease.com',
        'spamtrail.com',
        'spamtroll.net',
        'speed.1s.fr',
        'spoofmail.de',
        'squizzy.de',
        'ssoia.com',
        'startkeys.com',
        'stinkefinger.net',
        'stop-my-spam.cf',
        'stop-my-spam.com',
        'stop-my-spam.ga',
        'stop-my-spam.ml',
        'stop-my-spam.tk',
        'streetwisemail.com',
        'stuffmail.de',
        'super-auswahl.de',
        'supergreatmail.com',
        'supermailer.jp',
        'superrito.com',
        'superstachel.de',
        'suremail.info',
        'svk.jp',
        'sweetxxx.de',
        'tafmail.com',
        'tagyourself.com',
        'talkinator.com',
        'tapchicuoihoi.com',
        'techemail.com',
        'techgroup.me',
        'teewars.org',
        'teleosaurs.xyz',
        'teleworm.com',
        'teleworm.us',
        'temp.emeraldwebmail.com',
        'temp.headstrong.de',
        'temp15qm.com',
        'tempail.com',
        'tempalias.com',
        'tempe-mail.com',
        'tempemail.biz',
        'tempemail.co.za',
        'tempemail.com',
        'tempemail.net',
        'tempinbox.co.uk',
        'tempinbox.com',
        'tempmail.co',
        'tempmail.de',
        'tempmail.eu',
        'tempmail.it',
        'tempmail.net',
        'tempmail.us',
        'tempmail2.com',
        'tempmaildemo.com',
        'tempmailer.com',
        'tempmailer.de',
        'tempomail.fr',
        'temporarily.de',
        'temporarioemail.com.br',
        'temporaryemail.net',
        'temporaryemail.us',
        'temporaryforwarding.com',
        'temporaryinbox.com',
        'temporarymailaddress.com',
        'tempthe.net',
        'tempymail.com',
        'thanksnospam.info',
        'thankyou2010.com',
        'thc.st',
        'thelimestones.com',
        'thisisnotmyrealemail.com',
        'thismail.net',
        'thismail.ru',
        'throam.com',
        'throwam.com',
        'throwawayemailaddress.com',
        'throwawaymail.com',
        'tilien.com',
        'tittbit.in',
        'tmailinator.com',
        'tmail.ws',
        'toiea.com',
        'tokenmail.de',
        'tonymanso.com',
        'toomail.biz',
        'topranklist.de',
        'tradermail.info',
        'trash-amil.com',
        'trash-mail.at',
        'trash-mail.com',
        'trash-mail.de',
        'trash-mail.ga',
        'trash-mail.gq',
        'trash-mail.ml',
        'trash-mail.tk',
        'trash2009.com',
        'trash2010.com',
        'trash2011.com',
        'trashbox.eu',
        'trashcanmail.com',
        'trashdevil.com',
        'trashdevil.de',
        'trashemail.de',
        'trashmail.at',
        'trashmail.com',
        'trashmail.de',
        'trashmail.me',
        'trashmail.net',
        'trashmail.org',
        'trashmail.ws',
        'trashmailer.com',
        'trashymail.com',
        'trashymail.net',
        'trbvm.com',
        'trickmail.net',
        'trillianpro.com',
        'tryalert.com',
        'turual.com',
        'twinmail.de',
        'twoweirdtricks.com',
        'tyldd.com',
        'uggsrock.com',
        'umail.net',
        'upliftnow.com',
        'uplipht.com',
        'uroid.com',
        'us.af',
        'valemail.net',
        'venompen.com',
        'veryrealemail.com',
        'viditag.com',
        'viewcastmedia.com',
        'viewcastmedia.net',
        'viewcastmedia.org',
        'viralplays.com',
        'vkcode.ru',
        'vomoto.com',
        'vubby.com',
        'wasteland.rfc822.org',
        'webemail.me',
        'webm4il.info',
        'webuser.in',
        'wee.my',
        'weg-werf-email.de',
        'wegwerf-emails.de',
        'wegwerfadresse.de',
        'wegwerfemail.com',
        'wegwerfemail.de',
        'wegwerfmail.de',
        'wegwerfmail.info',
        'wegwerfmail.net',
        'wegwerfmail.org',
        'wetrainbayou.com',
        'wh4f.org',
        'whatiaas.com',
        'whatpaas.com',
        'whopy.com',
        'whtjddn.33mail.com',
        'whyspam.me',
        'willhackforfood.biz',
        'willselfdestruct.com',
        'winemaven.info',
        'wolfsmail.tk',
        'wollan.info',
        'worldspace.link',
        'wronghead.com',
        'wuzup.net',
        'wuzupmail.net',
        'wwwnew.eu',
        'x.ip6.li',
        'xagloo.co',
        'xagloo.com',
        'xemaps.com',
        'xents.com',
        'xmaily.com',
        'xn--9kq967o.com',
        'xoxy.net',
        'xww.ro',
        'yapped.net',
        'yeah.net',
        'yep.it',
        'yogamaven.com',
        'yopmail.com',
        'yopmail.fr',
        'yopmail.net',
        'yourdomain.com',
        'ypmail.webarnak.fr.eu.org',
        'yuurok.com',
        'z1p.biz',
        'za.com',
        'zehnminuten.de',
        'zehnminutenmail.de',
        'zetmail.com',
        'zippymail.info',
        'zoaxe.com',
        'zoemail.com',
        'zoemail.net',
        'zoemail.org',
        'zomg.info',
        'zxcv.com',
        'zxcvbnm.com',
        'zzz.com',

        // Russian email domains (safe to block for Indian users)
        'yandex.ru',
        'yandex.com',
        'yandex.ua',
        'yandex.kz',
        'yandex.by',
        'ya.ru',
        'mail.ru',
        'inbox.ru',
        'list.ru',
        'bk.ru',
        'internet.ru',
        'rambler.ru',
        'lenta.ru',
        'autorambler.ru',
        'myrambler.ru',
        'ro.ru',
        'r0.ru',

        // cock.li domains (anonymous/temp mail)
        'cock.li',
        'cock.email',
        'airmail.cc',
        'firemail.cc',
        'getbackinthe.kitchen',
        'memeware.net',
        'national.shitposting.agency',
        'tfwno.gf',
        'waifu.club',
        '420blaze.it',
        'aaathats3as.com',
        'horsefucker.org',
        'cocaine.ninja',
        'dicksinhisan.us',
        'loves.dicksinhisan.us',
        'wants.dicksinhisan.us',
        'goat.si',
        'nigge.rs',

        // 10minute-mail.org / den.yt network domains
        '10minute-mail.org',
        'den.yt',
        'denipl.net',
        'denipl.com',
        'forexzig.com',
        'fxzig.com',
        'lyricslrc.com',
        'dreamclarify.org',
        'asciibinder.net',
        'myfxspot.com',
        'thetechnext.net',
        'logsmarter.net',
        'azuretechtalk.net',
        'pelagius.net',
        'cyclelove.cc',
        'polkaroad.net',
    ];

    /**
     * MX hostname patterns that indicate temporary email services
     * These patterns match against the MX record hostname
     */
    protected $blockedMxPatterns = [
        // Direct service names
        'temp-mail',
        'tempmail',
        'temp.mail',
        'guerrillamail',
        'guerrilla',
        'mailinator',
        'sharklasers',
        'spam4',
        'grr.la',
        'pokemail',
        'throwaway',
        'disposable',
        'trashmail',
        'fakeinbox',
        'fakemail',
        'tempinbox',
        'dropmail',
        'mohmal',
        'yopmail',
        '10minutemail',
        '10minute',
        'minutemail',
        'getairmail',
        'getnada',
        'emailondeck',
        'maildrop',
        'mailnesia',
        'mailcatch',
        'spamgourmet',
        'spambox',
        'spamcowboy',
        'anonymbox',
        'anonbox',
        'mytrashmail',
        'jetable',
        'meltmail',
        'dispostable',
        'spamfree',
        'nospam',
        'antispam',
        'tempr.email',
        'mail-temp',
        'mail.temp',
        'fake-mail',
        'email-fake',
        'emailfake',
        'mail-fake',
        'generator.email',
        'burnermail',
        'burnmail',
        'tempumail',
        'internxt',
        'tempm.com',

        // tempmailo.com MX patterns
        'plingest.com',
        'mainnetmail',
        'catchservers',
        'eye-mail.net',
        'rejecthost',

        // Russian email services (safe to block for Indian users)
        'yandex.net',
        'yandex.ru',
        'yandex.com',
        'mail.ru',
        'rambler.ru',
        'cock.li',
        'cock.email',
        'airmail.cc',
        'firemail.cc',
        'national.shitposting',
        'tfwno.gf',
        'waifu.club',
        'horsefucker.org',
        'cocaine.ninja',
        'dicksinhisan.us',
        'loves.dicksinhisan.us',

        // 10minute-mail.org / den.yt network
        'den.yt',
        'mx2.den.yt',
        'mx.den.yt',
        'denipl',
    ];

    /**
     * Handle an incoming request
     */
    public function handle(Request $request, Closure $next)
    {
        $email = $request->input('email');

        if ($email) {
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->blockResponse('Invalid email address.');
            }

            $domain = strtolower(substr(strrchr($email, "@"), 1));

            // Cache the result for 24 hours to avoid repeated DNS lookups
            $isDisposable = Cache::remember("disposable_email_{$domain}", 86400, function () use ($domain) {
                return $this->isDisposable($domain);
            });

            if ($isDisposable) {
                return $this->blockResponse('Temporary email addresses are not allowed.');
            }
        }

        return $next($request);
    }

    /**
     * Check if a domain is a disposable email provider
     */
    protected function isDisposable(string $domain): bool
    {
        // Check 1: Direct domain blocklist (fastest)
        if (in_array($domain, $this->blockedDomains)) {
            return true;
        }

        // Check 2: MX record checks
        try {
            $mxRecords = dns_get_record($domain, DNS_MX);

            // No MX record = suspicious (most legitimate domains have MX records)
            if (!$mxRecords || empty($mxRecords)) {
                // Don't block just because no MX, but flag for API check
                return $this->checkApi($domain);
            }

            foreach ($mxRecords as $mx) {
                $mxHost = strtolower($mx['target'] ?? '');

                // Check 2a: MX hostname patterns
                foreach ($this->blockedMxPatterns as $pattern) {
                    if (str_contains($mxHost, $pattern)) {
                        return true;
                    }
                }

                // Check 2b: MX server IP addresses
                $mxIps = @gethostbynamel($mxHost);
                if ($mxIps) {
                    foreach ($mxIps as $ip) {
                        // Exact IP match
                        if (in_array($ip, $this->blockedMxIps)) {
                            return true;
                        }

                        // IP range/prefix match (e.g., blocks entire 91.195.240.x subnet)
                        foreach ($this->blockedIpRanges as $range) {
                            if (str_starts_with($ip, $range)) {
                                return true;
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // DNS lookup failed, continue to API check
        }

        // Check 3: Optional API fallback (catches new domains)
        return $this->checkApi($domain);
    }

    /**
     * Check domain using external API (optional, catches new domains)
     * 
     * Uses free Kickbox API - no API key required
     * You can replace with other services like:
     * - Abstract API (100 free/month)
     * - UserCheck API (1000 free/month)
     */
    protected function checkApi(string $domain): bool
    {
        // Skip API check if you want to rely only on local lists
        // Uncomment the line below to disable API checks:
        // return false;

        try {
            $response = Http::timeout(3)->get("https://open.kickbox.com/v1/disposable/{$domain}");

            if ($response->successful()) {
                return $response->json('disposable') === true;
            }
        } catch (\Exception $e) {
            // API failed, don't block the user
        }

        return false;
    }

    /**
     * Return a JSON response for blocked emails
     */
    protected function blockResponse(string $message)
    {
        return response()->json([
            'status' => false,
            'message' => $message
        ], 422);
    }
}
