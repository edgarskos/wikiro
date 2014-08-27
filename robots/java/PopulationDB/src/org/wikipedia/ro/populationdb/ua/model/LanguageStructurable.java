package org.wikipedia.ro.populationdb.ua.model;

import java.util.Map;

public interface LanguageStructurable {

    public abstract Map<Language, Double> getLanguageStructure();

    public abstract String getGenitive();

    public abstract String getName();

    public abstract String getRomanianName();

    public abstract String getTransliteratedName();

    public abstract Region computeRegion();

}
