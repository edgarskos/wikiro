package org.wikipedia.ro.populationdb.hr;

import static org.apache.commons.lang3.BooleanUtils.isTrue;
import static org.apache.commons.lang3.ObjectUtils.defaultIfNull;
import static org.apache.commons.lang3.StringUtils.defaultString;
import static org.apache.commons.lang3.StringUtils.startsWithAny;

import java.awt.Color;
import java.io.IOException;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collections;
import java.util.HashMap;
import java.util.Hashtable;
import java.util.List;
import java.util.Map;
import java.util.Properties;
import java.util.regex.Pattern;

import javax.security.auth.login.FailedLoginException;

import org.apache.commons.lang3.ObjectUtils;
import org.apache.commons.lang3.StringUtils;
import org.hibernate.HibernateException;
import org.hibernate.Session;
import org.jfree.data.general.DefaultPieDataset;
import org.stringtemplate.v4.ST;
import org.stringtemplate.v4.STGroup;
import org.stringtemplate.v4.STGroupFile;
import org.wikibase.Wikibase;
import org.wikipedia.Wiki;
import org.wikipedia.ro.populationdb.hr.dao.Hibernator;
import org.wikipedia.ro.populationdb.hr.model.Commune;
import org.wikipedia.ro.populationdb.hr.model.County;
import org.wikipedia.ro.populationdb.hr.model.EthnicallyStructurable;
import org.wikipedia.ro.populationdb.hr.model.Nationality;

public class HRWikiGenerator {
    private static final String NOTE_REFLIST = "\n== Note ==\n{{Reflist}}\n";
    private static final int NEW_PAGE_LIMIT = 1000;
    private Wiki rowiki;
    private Wiki hrwiki;
    private Wikibase dwiki;
    private Hibernator hib;
    private Session ses;
    private final Pattern footnotesRegex = Pattern
        .compile("\\{\\{(?:(?:L|l)istănote|(?:R|r)eflist)|(?:\\<\\s*references\\s*\\/\\>)");
    private final STGroup communeTemplateGroup = new STGroupFile("templates/hr/commune.stg");
    private final STGroup townTemplateGroup = new STGroupFile("templates/hr/town.stg");
    private Hashtable<Nationality, Color> nationColorMap;
    private Hashtable<String, Nationality> nationNameMap;

    public static void main(final String[] args) throws FailedLoginException, IOException {
        final HRWikiGenerator generator = new HRWikiGenerator();
        try {
            generator.init();
            generator.generateCounties();
        } finally {
            generator.close();
        }
    }

    private void generateCounties() throws IOException {
        final List<County> counties = hib.getAllCounties();

        for (final County county : counties) {
            final List<Commune> communes = hib.getCommunesByCounty(county);
            for (final Commune com : communes) {
                generateCommune(com);
            }
        }
    }

    private void generateCommune(final Commune com) throws IOException {
        final String title = getExistingRoTitleOfArticleWithSubject(com);
        final boolean newPage = isNewPage(com, title);
        if (newPage) {
            generateNewCommuneArticle(com);
        } else {
            addDemographySectionToExistingArticle(com, title);
        }
    }

    private void addDemographySectionToExistingArticle(final Commune com, final String title) throws IOException {
        final String demographySection = generateDemographySection(com);
        final String pageText = rowiki.getPageText(title);
        final List<String> markers = Arrays.asList("==Note", "== Note", "== Vezi și", "==Vezi și", "[[Categorie:", "{{Ciot",
            "{{ciot", "{{Croatia");
        final List<Integer> markerLocations = createMarkerLocationList(pageText, markers);

        int insertLocation;
        if (markerLocations.size() > 0) {
            insertLocation = Collections.min(markerLocations);
        } else {
            insertLocation = -1;
        }

        final StringBuilder sbuild = new StringBuilder(pageText);
        if (!footnotesRegex.matcher(sbuild).find()) {
            if (insertLocation > 0) {
                sbuild.insert(insertLocation, NOTE_REFLIST);
            } else {
                sbuild.append(NOTE_REFLIST);
            }
        }
        if (insertLocation > 0) {
            sbuild.insert(insertLocation, demographySection);
        } else {
            sbuild.append(demographySection);
        }
    }

    private List<Integer> createMarkerLocationList(final String pageText, final List<String> markers) {
        final List<Integer> ret = new ArrayList<Integer>();
        for (final String marker : markers) {
            final int markerLocation = StringUtils.indexOf(pageText, marker);
            if (0 <= markerLocation) {
                ret.add(markerLocation);
            }
        }

        return ret;
    }

    private String generateDemographySection(final Commune com) {
        final StringBuilder demographics = new StringBuilder(
            "\n== Demografie ==\n<!-- Start secțiune generată de Andrebot -->");
        final STGroup templateGroup = com.getTown() > 0 ? townTemplateGroup : communeTemplateGroup;
        final ST piechart = templateGroup.getInstanceOf("piechart");
        final int population = com.getPopulation();
        piechart.add("nume", com.getName());

        final Map<Nationality, Integer> ethnicStructure = com.getEthnicStructure();
        final DefaultPieDataset dataset = new DefaultPieDataset();
        final int totalKnownEthnicity = computeEthnicityDataset(population, ethnicStructure, dataset);

        renderPiechart(demographics, piechart, population, dataset);

        final ST demogIntro = templateGroup.getInstanceOf("introTmpl");
        demogIntro.add("nume", com.getName());
        demogIntro.add("tip", com.getTown() > 1 ? "oraș" : "sat");
        demogIntro.add("populatie", population);
        demographics.append(demogIntro.render());

        final Nationality majNat = getMajority(com);
        final List<Nationality> minorities = getMinorities(com);
        writeEthnodemographics(templateGroup, demographics, population, ethnicStructure, majNat, minorities);

        writeUnknownEthnicity(templateGroup, demographics, population, totalKnownEthnicity);

        demographics.append("\n<!--Sfârșit secțiune generată de Andrebot -->");
        return demographics.toString();
    }

    private int computeEthnicityDataset(final int population, final Map<Nationality, Integer> ethnicStructure,
                                        final DefaultPieDataset dataset) {
        final Map<String, Integer> smallGroups = new HashMap<String, Integer>();
        final Nationality undeclared = hib.getNationalityByName("Nedeclarat");
        int totalKnownEthnicity = 0;
        Nationality otherNat = null;
        for (final Nationality nat : nationColorMap.keySet()) {
            final int natpop = defaultIfNull(ethnicStructure.get(nat), 0);
            if (natpop * 100.0 / population > 1.0 && !StringUtils.equals(nat.getName(), "Neclasificat")) {
                dataset.setValue(nat.getName(), natpop);
            } else if (natpop * 100.0 / population <= 1.0 && !StringUtils.equals(nat.getName(), "Neclasificat")) {
                smallGroups.put(nat.getName(), natpop);
            } else if (StringUtils.equals(nat.getName(), "Neclasificat")) {
                otherNat = nat;
            }
            if (!StringUtils.equals(undeclared.getName(), nat.getName())) {
                totalKnownEthnicity += natpop;
            }
        }
        dataset.setValue("Nicio identificare", population - totalKnownEthnicity);
        if (1 < smallGroups.size()) {
            smallGroups.put("Neclasificat", ethnicStructure.get(otherNat));
            int smallSum = 0;
            for (final String smallGroup : smallGroups.keySet()) {
                smallSum += ObjectUtils.defaultIfNull(smallGroups.get(smallGroup), 0);
            }
            dataset.setValue("Neclasificat", smallSum);
        } else {
            for (final String natname : smallGroups.keySet()) {
                if (!startsWithAny(natname, "Ne") && !startsWithAny(natname, "Afiliați") && smallGroups.containsKey(natname)) {
                    dataset.setValue(natname, smallGroups.get(natname));
                }
            }
            if (ethnicStructure.containsKey(otherNat)) {
                dataset.setValue("Neclasificat", ethnicStructure.get(otherNat));
            }
        }
        return totalKnownEthnicity;
    }

    private boolean isNewPage(final Commune com, final String title) throws IOException {
        if (title == null) {
            return true;
        }
        final Map pageInfo = rowiki.getPageInfo(title);
        final Integer intSup = (Integer) pageInfo.get("size");
        if (null != intSup && intSup < NEW_PAGE_LIMIT) {
            return true;
        }
        return false;
    }

    private void close() {
        ses.getTransaction().rollback();
        if (null != rowiki) {
            rowiki.logout();
        }
        if (null != hrwiki) {
            hrwiki.logout();
        }
        if (null != dwiki) {
            dwiki.logout();
        }

    }

    private void init() throws FailedLoginException, IOException {
        rowiki = new Wiki("ro.wikipedia.org");
        hrwiki = new Wiki("hr.wikipedia.org");
        dwiki = new Wikibase();

        final Properties credentials = new Properties();
        credentials.load(HRWikiGenerator.class.getClassLoader().getResourceAsStream("credentials.properties"));
        final String datauser = credentials.getProperty("UsernameData");
        final String datapass = credentials.getProperty("PasswordData");
        final String user = credentials.getProperty("Username");
        final String pass = credentials.getProperty("Password");
        rowiki.login(user, pass.toCharArray());
        rowiki.setMarkBot(true);
        dwiki.login(datauser, datapass.toCharArray());

        hib = new Hibernator();
        ses = hib.getSession();
        ses.beginTransaction();

        assignColorToNationality("Români", new Color(85, 85, 255));
        assignColorToNationality("Turci", new Color(255, 85, 85));
        assignColorToNationality("Romi", new Color(85, 255, 255));
        assignColorToNationality("Maghiari", new Color(85, 255, 85));
        assignColorToNationality("Evrei", new Color(192, 192, 192));
        assignColorToNationality("Croați", new Color(0, 0, 128));
        assignColorToNationality("Sârbi", new Color(128, 0, 0));
        assignColorToNationality("Bosniaci", new Color(64, 64, 192));
        assignColorToNationality("Austrieci", new Color(255, 255, 255));
        assignColorToNationality("Albanezi", new Color(192, 64, 192));
        assignColorToNationality("Vlahi", new Color(128, 128, 255));
        assignColorToNationality("Ucraineni", new Color(255, 255, 85));
        assignColorToNationality("Ruteni", new Color(255, 255, 128));
        assignColorToNationality("Germani", new Color(192, 192, 64));
        assignColorToNationality("Albanezi", new Color(192, 64, 64));
        assignColorToNationality("Muntenegreni", new Color(255, 85, 255));
        assignColorToNationality("Italieni", new Color(64, 192, 64));
        assignColorToNationality("Sloveni", new Color(32, 32, 128));
        assignColorToNationality("Slovaci", new Color(48, 48, 160));
        assignColorToNationality("Neclasificat", new Color(192, 192, 192));

        blandifyColors(nationColorMap);

    }

    private static void blandifyColors(final Hashtable<Nationality, Color> colorMap) {
        for (final Nationality key : colorMap.keySet()) {
            final Color color = colorMap.get(key);
            final int[] colorcomps = new int[3];
            colorcomps[0] = color.getRed();
            colorcomps[1] = color.getGreen();
            colorcomps[2] = color.getBlue();

            for (int i = 0; i < colorcomps.length; i++) {
                if (colorcomps[i] == 0) {
                    colorcomps[i] = 0x3f;
                } else if (colorcomps[i] == 85) {
                    colorcomps[i] = 128;
                } else if (colorcomps[i] == 64) {
                    colorcomps[i] = 85;
                } else if (colorcomps[i] == 128) {
                    colorcomps[i] = 0x9f;
                }
            }
            colorMap.put(key, new Color(colorcomps[0], colorcomps[1], colorcomps[2]));
        }
    }

    private void assignColorToNationality(final String nationalityName, final Color color) throws HibernateException {
        final Nationality nat = hib.getNationalityByName(nationalityName);
        if (null != nat) {
            nationColorMap.put(nat, color);
            nationNameMap.put(nat.getName(), nat);
        }
    }

    private String getExistingRoTitleOfArticleWithSubject(final Commune obshtina) throws IOException {
        final List<String> alternativeTitles = new ArrayList<String>();
        if (obshtina.getTown() > 0) {
            alternativeTitles.add(obshtina.getName());
            alternativeTitles.add(obshtina.getName() + ", Croația");
            alternativeTitles.add(obshtina.getName() + ", " + obshtina.getCounty().getName());
        } else {
            alternativeTitles.add("Comuna " + obshtina.getName() + ", " + obshtina.getCounty().getName());
            alternativeTitles.add("Comuna " + obshtina.getName());
        }

        for (final String candidateTitle : alternativeTitles) {
            final HashMap pageInfo = rowiki.getPageInfo(candidateTitle);
            final Boolean exists = (Boolean) pageInfo.get("exists");
            if (!isTrue(exists)) {
                continue;
            }
            final String pageTitle = defaultString(rowiki.resolveRedirect(candidateTitle), candidateTitle);
            final String[] categs = rowiki.getCategories(pageTitle);
            for (final String categ : categs) {
                if (StringUtils.startsWithAny(categ, "Orașe în Croația", "Orașe în cantonul ", "Comune în Croația",
                    "Comune în cantonul ")) {
                    return pageTitle;
                }
            }
        }
        return null;
    }

    private List<Nationality> getMinorities(final EthnicallyStructurable obshtina) {
        final List<Nationality> ret = new ArrayList<Nationality>();
        for (final Nationality nat : obshtina.getEthnicStructure().keySet()) {
            final double weight = obshtina.getEthnicStructure().get(nat) / (double) obshtina.getPopulation();
            if (weight <= 0.5 && weight > 0.01 && !startsWithAny(nat.getName(), "Altele", "Ne")) {
                ret.add(nat);
            }
        }
        return ret;
    }

    private Nationality getMajority(final EthnicallyStructurable obshtina) {
        for (final Nationality nat : obshtina.getEthnicStructure().keySet()) {
            if (obshtina.getEthnicStructure().get(nat) / (double) obshtina.getPopulation() > 0.5) {
                return nat;
            }
        }
        return null;
    }
}